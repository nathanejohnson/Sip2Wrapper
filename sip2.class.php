<?php

/**
 * @package    
 * @author     John Wohlers <john@wohlershome.net>
 * @license    http://opensource.org/licenses/gpl-3.0.html
 * @copyright  John Wohlers <john@wohlershome.net>
 * @version    $Id: sip2.class.php 28 2010-10-08 21:06:51Z cap60552 $
 * @link       http://php-sip2.googlecode.com/
 */

/**
 * SIP2 Class
 * 
 * This class provides a method of communicating with an Integrated
 * Library System using 3M's SIP2 standard. 
 *
 * PHP version 5
 * 
 * Changelog:
 * 
 * 2012.02.05:
 * 
 * - Fixed some underlying issues in the handling of CRC checking and Sequence number usage
 * by adding public variables and making sure they  are respected througout other in the class
 *  
 * - Whitespace formatting cleanup and consistency (replace some tabs with spaces)
 * Add docblock style commenting to all functions
 *
 * - Fleshed out usage example with checks
 *
 * 2010.10.08:
 * 
 * - Fixed a potential endless loop condition if a socket lost connection in the middle of a transaction.
 *
 * 2008.04.11:
 * 
 * - Encorported a bug fix submitted by Bob Wicksall
 *
 * TODO:
 *
 * - Clean up variable names, check for consistancy
 * - Add better i18n support, including functions to handle the SIP2 language definitions
 *
 * Usage Example:
 * 
 * ```php
 * // include the class
 * include('sip2.class.php');
 *
 * // create object
 * $mysip = new sip2;
 *
 * // set host name
 * $mysip->hostname = 'server.example.com';
 * $mysip->port = 6002;
 *
 * // identify a patron
 * $mysip->patron = '101010101';
 * $mysip->patronpwd = '010101';
 *
 * // connect to SIP server 
 * $result = $mysip->connect();
 *
 * // check result
 * if (!$result) {
 *   die('could not connect');
 * }
 *
 * // generate login message
 * $msg = $mysip->msgLogin('login', 'password');
 *
 * // get login response
 * $login = $mysip->parseLoginResponse( $mysip->get_message($msg) );
 *
 * // check login response
 * if ($login['fixed']['Ok'] != 1) {
 *   die('login failed');
 * }
 *
 * // generate a self check message
 * $msg = $mysip->msgSCStatus();
 *
 * // execute a self check
 * $check = $mysip->parseACStatusResponse( $mysip->get_message($msg) );
 *
 * // verify return
 * if ($status['fixed']['Online'] != 'Y') {
 *   die('system not online');
 * }
 *
 * // generate charged items request message
 * $msg = $mysip->msgPatronInformation('charged');
 *
 * // parse the raw response into an array
 * $charged = $mysip->parsePatronInfoResponse( $mysip->get_message($msg) );
 * ```
 */

class sip2
{

    /**
     * instance hostname
     * @var string
     */
    public $hostname;

    /**
     * port number
     * @var int
     */
    public $port            = 6002;

    /**
     * language code (001 == english)
     * @var string
     */
    public $language        = '001';

    /**
     * patron identifier (AA)
     * @var string
     */
    public $patron          = '';

    /**
     * patron password (AD)
     * @var string
     */
    public $patronpwd       = '';

    /**
     * terminal password (AC)
     * @var string
     */
    public $AC              = '';

    /**
     * maximum number of resends allowed before we give up
     * @var integer
     */
    public $maxretry        = 3;

    /**
     * field terminator
     * @var string
     */
    public $fldTerminator   = '|';

    /**
     * message terminator
     * @var string
     */
    public $msgTerminator   = "\r";

    /**
     * login encryption algorithm type (0 = plain text)
     * @var integer
     */
    public $UIDalgorithm    = 0;

    /**
     * password encryption algorithm type (undocumented)
     * @var integer
     */
    public $PWDalgorithm    = 0;

    /**
     * location code
     * @var string
     */
    public $scLocation      = '';

    /**
     * toggle crc checking and appending
     * @var boolean
     */
    public $withCrc         = true;

    /**
     * toggle the use of sequence numbers
     * @var boolean
     */
    public $withSeq         = true;

    /**
     * debug logging toggle
     * @var boolean
     */
    public $debug           = false;

    /**
     * value for the AO field
     * @var string
     */
    public $AO = 'WohlersSIP';

    /**
     * value for the AN field
     * @var string
     */
    public $AN = 'SIPCHK';

    /**
     * raw socket connection
     * @var object
     */
    private $socket;

    /**
     * internal sequence number
     * @var integer
     */
    private $seq = -1;

    /**
     * internal retry counter
     * @var integer
     */
    private $retry = 0;

    /**
     * internal message build buffer
     * @var string
     */
    private $msgBuild = '';

    /**
     * internal message build toggle
     * @var boolean
     */
    private $noFixed = false;

    /**
     * Generate Patron Status (code 23) request messages in sip2 format
     * @return string SIP2 request message
     * @api
     */
    function msgPatronStatusRequest() 
    {
        /* Server Response: Patron Status Response message. */
        $this->_newMessage('23');
        $this->_addFixedOption($this->language, 3);
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AA',$this->patron);
        $this->_addVarOption('AC',$this->AC);
        $this->_addVarOption('AD',$this->patronpwd);
        return $this->_returnMessage();
    }

    /**
     * Generate Checkout (code 11) request messages in sip2 format
     * @param  string $item      value for the variable length required AB field
     * @param  string $nbDateDue optional override for default due date (default '')
     * @param  string $scRenewal value for the renewal portion of the fixed length field (default N)
     * @param  string $itmProp   value for the variable length optional CH field (default '')
     * @param  string $fee       value for the variable length optional BO field (default N)
     * @param  string $noBlock   value for the blocking portion of the fixed length field (default N)
     * @param  string $cancel    value for the variable length optional BI field (default N)
     * @return string            SIP2 request message
     * @api
     */
    function msgCheckout($item, $nbDateDue ='', $scRenewal='N', $itmProp ='', $fee='N', $noBlock='N', $cancel='N') 
    {
        /* Checkout an item  (11) - untested */
        $this->_newMessage('11');
        $this->_addFixedOption($scRenewal, 1);
        $this->_addFixedOption($noBlock, 1);
        $this->_addFixedOption($this->_datestamp(), 18);
        if ($nbDateDue != '') {
            /* override defualt date due */
            $this->_addFixedOption($this->_datestamp($nbDateDue), 18);
        } else {
            /* send a blank date due to allow ACS to use default date due computed for item */
            $this->_addFixedOption('', 18);
        }
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AA',$this->patron);
        $this->_addVarOption('AB',$item);
        $this->_addVarOption('AC',$this->AC);
        $this->_addVarOption('CH',$itmProp, true);
        $this->_addVarOption('AD',$this->patronpwd, true);
        $this->_addVarOption('BO',$fee, true); /* Y or N */
        $this->_addVarOption('BI',$cancel, true); /* Y or N */

        return $this->_returnMessage();
    }

    /**
     * Generate Checkin (code 09) request messages in sip2 format
     * @param  string $item          value for the variable length required AB field
     * @param  string $itmReturnDate value for the return date portion of the fixed length field
     * @param  string $itmLocation   value for the variable length required AP field (default '')
     * @param  string $itmProp       value for the variable length optional CH field (default '')
     * @param  string $noBlock       value for the blocking portion of the fixed length field (default N)
     * @param  string $cancel        value for the variable length optional BI field (default N)
     * @return string                SIP2 request message
     * @api 
     */
    function msgCheckin($item, $itmReturnDate, $itmLocation = '', $itmProp = '', $noBlock='N', $cancel = '') 
    {
        /* Checkin an item (09) - untested */
        if ($itmLocation == '') {
            /* If no location is specified, assume the defualt location of the SC, behavior suggested by spec*/
            $itmLocation = $this->scLocation;
        }

        $this->_newMessage('09');
        $this->_addFixedOption($noBlock, 1);
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addFixedOption($this->_datestamp($itmReturnDate), 18);
        $this->_addVarOption('AP',$itmLocation);
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AB',$item);
        $this->_addVarOption('AC',$this->AC);
        $this->_addVarOption('CH',$itmProp, true);
        $this->_addVarOption('BI',$cancel, true); /* Y or N */

        return $this->_returnMessage();
    }

    /**
     * Generate Block Patron (code 11) request messages in sip2 format
     * @param  string $message   message value for the required variable length AL field
     * @param  string $retained  value for the retained portion of the fixed length field (default N)
     * @return string            SIP2 request message
     * @api
     */
    function msgBlockPatron($message, $retained='N') 
    {
        /* Blocks a patron, and responds with a patron status response  (01) - untested */
        $this->_newMessage('01');
        $this->_addFixedOption($retained, 1); /* Y if card has been retained */
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AL',$message);
        $this->_addVarOption('AA',$this->AA);
        $this->_addVarOption('AC',$this->AC);

        return $this->_returnMessage();
    }

    /**
     * Generate SC Status (code 99) request messages in sip2 format
     * @param  int $status  status code
     * @param  int $width   message width (default 80)
     * @param  int $version prootocol version (default 2)
     * @return string|false SIP2 request message or false on error
     * @api
     */
    function msgSCStatus($status = 0, $width = 80, $version = 2) 
    {
        /* selfcheck status message, this should be sent immediatly after login  - untested */
        /* status codes, from the spec:
        * 0 SC unit is OK
        * 1 SC printer is out of paper
        * 2 SC is about to shut down
        */

        if ($version > 3) {
            $version = 2;
        }

        if ($status < 0 || $status > 2) {
            $this->_debugmsg( "SIP2: Invalid status passed to msgSCStatus" );
            return false;
        }

        $this->_newMessage('99');
        $this->_addFixedOption($status, 1);
        $this->_addFixedOption($width, 3);
        $this->_addFixedOption(sprintf("%03.2f",$version), 4);
        return $this->_returnMessage();
    }

    /**
     * Generate ACS Resend (code 97) request messages in sip2 format
     * @return string SIP2 request message
     * @api
     */
    function msgRequestACSResend () 
    {
        /* Used to request a resend due to CRC mismatch - No sequence number is used */
        $this->_newMessage('97');
        return $this->_returnMessage(false);
    }

    /**
     * Generate login (code 93) request messages in sip2 format
     * @param  string $sipLogin    login value for the CN field
     * @param  string $sipPassword password value for the CO field
     * @return string              SIP2 request message
     * @api
     */
    function msgLogin($sipLogin, $sipPassword) 
    {
        /* Login (93) - untested */
        $this->_newMessage('93');
        $this->_addFixedOption($this->UIDalgorithm, 1);
        $this->_addFixedOption($this->PWDalgorithm, 1);
        $this->_addVarOption('CN',$sipLogin);
        $this->_addVarOption('CO',$sipPassword);
        $this->_addVarOption('CP',$this->scLocation, true);
        return $this->_returnMessage();
    }

    /**
     * Generate Patron Information (code 63) request messages in sip2 format
     * @param  string $type  type of information request (none, hold, overdue, charged, fine, recall, unavail)
     * @param  string $start value for BP field (default 1)
     * @param  string $end   value for BQ field (default 5)
     * @return string        SIP2 request message
     * @api
     */
    function msgPatronInformation($type, $start = '1', $end = '5') 
    {
        /* 
        * According to the specification:
        * Only one category of items should be  requested at a time, i.e. it would take 6 of these messages, 
        * each with a different position set to Y, to get all the detailed information about a patron's items.
        */
        $summary['none']     = '      ';
        $summary['hold']     = 'Y     ';
        $summary['overdue']  = ' Y    ';
        $summary['charged']  = '  Y   ';
        $summary['fine']     = '   Y  ';
        $summary['recall']   = '    Y ';
        $summary['unavail']  = '     Y';

        /* Request patron information */
        $this->_newMessage('63');
        $this->_addFixedOption($this->language, 3);
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addFixedOption(sprintf("%-10s",$summary[$type]), 10);
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AA',$this->patron);
        $this->_addVarOption('AC',$this->AC, true);
        $this->_addVarOption('AD',$this->patronpwd, true);
        $this->_addVarOption('BP',$start, true); /* old function version used padded 5 digits, not sure why */
        $this->_addVarOption('BQ',$end, true); /* old function version used padded 5 digits, not sure why */
        return $this->_returnMessage();
    }

    /**
     * Generate End Patron Session (code 35) request messages in sip2 format
     * @return string SIP2 request message
     * @api
     */
    function msgEndPatronSession() 
    {
        /*  End Patron Session, should be sent before switching to a new patron. (35) - untested */
        $this->_newMessage('35');
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AA',$this->patron);
        $this->_addVarOption('AC',$this->AC, true);
        $this->_addVarOption('AD',$this->patronpwd, true);
        return $this->_returnMessage();
    }

    /**
     * Generate Fee Paid (code 37) request messages in sip2 format
     * @param  int    $feeType   value for the fee type portion of the fixed length field
     * @param  int    $pmtType   value for payment type portion of the fixed length field
     * @param  string $pmtAmount value for the payment amount variable length required BV field
     * @param  string $curType   value for the currency type portion of the fixed field
     * @param  string $feeId     value for the fee id variable length optional CG field
     * @param  string $transId   value for the transaction id variable length optional BK field
     * @return string|false      SIP2 request message or false on error
     * @api
     */
    function msgFeePaid ($feeType, $pmtType, $pmtAmount, $curType = 'USD', $feeId = '', $transId = '') 
    {
        /* Fee payment function (37) - untested */
        /* Fee Types: */
        /* 01 other/unknown */
        /* 02 administrative */
        /* 03 damage */
        /* 04 overdue */
        /* 05 processing */
        /* 06 rental*/
        /* 07 replacement */
        /* 08 computer access charge */
        /* 09 hold fee */

        /* Value Payment Type */
        /* 00   cash */
        /* 01   VISA */
        /* 02   credit card */

        if (!is_numeric($feeType) || $feeType > 99 || $feeType < 1) {
            /* not a valid fee type - exit */
            $this->_debugmsg( "SIP2: (msgFeePaid) Invalid fee type: {$feeType}");
            return false;
        }

        if (!is_numeric($pmtType) || $pmtType > 99 || $pmtType < 0) {
            /* not a valid payment type - exit */
            $this->_debugmsg( "SIP2: (msgFeePaid) Invalid payment type: {$pmtType}");
            return false;
        }

        $this->_newMessage('37');
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addFixedOption(sprintf('%02d', $feeType), 2);
        $this->_addFixedOption(sprintf('%02d', $pmtType), 2);
        $this->_addFixedOption($curType, 3); 
        $this->_addVarOption('BV',$pmtAmount); /* due to currancy format localization, it is up to the programmer to properly format their payment amount */
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AA',$this->patron);
        $this->_addVarOption('AC',$this->AC, true);
        $this->_addVarOption('AD',$this->patronpwd, true);
        $this->_addVarOption('CG',$feeId, true);
        $this->_addVarOption('BK',$transId, true);

        return $this->_returnMessage();
    }

    /**
     * Generate Item Information (code 17) request messages in sip2 format
     * @param  string $item value for the variable length required AB field
     * @return string       SIP2 request message
     * @api
     */
    function msgItemInformation($item) 
    {

        $this->_newMessage('17');
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AB',$item);
        $this->_addVarOption('AC',$this->AC, true);
        return $this->_returnMessage();
    }

    /**
     * Generate Item Status (code 19) request messages in sip2 format
     * @param  string $item     value for the variable length required AB field
     * @param  string $itmProp  value for the variable length required CH field
     * @return string           SIP2 request message
     * @api
     */
    function msgItemStatus ($item, $itmProp = '') 
    {
        /* Item status update function (19) - untested  */
        $this->_newMessage('19');
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AB',$item);
        $this->_addVarOption('AC',$this->AC, true);
        $this->_addVarOption('CH',$itmProp);
        return $this->_returnMessage();
    }

    /**
     * Generate Patron Enable (code 25) request messages in sip2 format
     * @return string SIP2 request message
     * @api
     */
    function msgPatronEnable () 
    {
        /* Patron Enable function (25) - untested */
        /* This message can be used by the SC to re-enable canceled patrons. It should only be used for system testing and validation. */
        $this->_newMessage('25');
        $this->_addFixedOption($this->_datestamp(), 18);
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AA',$this->patron);
        $this->_addVarOption('AC',$this->AC, true);
        $this->_addVarOption('AD',$this->patronpwd, true);
        return $this->_returnMessage();
    }

    /**
     * Generate Hold (code 15) request messages in sip2 format
     * @param  string $mode         value for the mode portion of the fixed length field
     * @param  string $expDate      value for the optional variable length BW field
     * @param  string $holdtype     value for the optional variable length BY field
     * @param  string $item         value for the optional variable length AB field
     * @param  string $title        value for the optional variable length AJ field
     * @param  string $fee          value for the optional variable length BO field
     * @param  string $pkupLocation value for the optional variable length BS field
     * @return string|false         SIP2 request message or false on error
     * @api
     */
    function msgHold($mode, $expDate = '', $holdtype = '', $item = '', $title = '', $fee='N', $pkupLocation = '') 
    {
        /* mode validity check */
        /* 
         * - remove hold
         * + place hold
         * * modify hold
         */
        if (strpos('-+*',$mode) === false) {
            /* not a valid mode - exit */
            $this->_debugmsg( "SIP2: Invalid hold mode: {$mode}");
            return false;
        }

        if ($holdtype != '' && ($holdtype < 1 || $holdtype > 9)) {
        /*
         * Valid hold types range from 1 - 9 
         * 1   other
         * 2   any copy of title
         * 3   specific copy
         * 4   any copy at a single branch or location
         */
            $this->_debugmsg( "SIP2: Invalid hold type code: {$holdtype}");
            return false;
        }

        $this->_newMessage('15');
        $this->_addFixedOption($mode, 1);
        $this->_addFixedOption($this->_datestamp(), 18);
        if ($expDate != '') {
            /* hold expiration date,  due to the use of the datestamp function, we have to check here for empty value. when datestamp is passed an empty value it will generate a current datestamp */
            $this->_addVarOption('BW', $this->_datestamp($expDate), true); /*spec says this is fixed field, but it behaves like a var field and is optional... */
        }
        $this->_addVarOption('BS',$pkupLocation, true);
        $this->_addVarOption('BY',$holdtype, true);
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AA',$this->patron);
        $this->_addVarOption('AD',$this->patronpwd, true);
        $this->_addVarOption('AB',$item, true);
        $this->_addVarOption('AJ',$title, true);
        $this->_addVarOption('AC',$this->AC, true);
        $this->_addVarOption('BO',$fee, true); /* Y when user has agreed to a fee notice */

        return $this->_returnMessage();
    }

    /**
     * Generate Renew (code 29) request messages in sip2 format
     * @param  string $item       value for the variable length optional AB field
     * @param  string $title      value for the variable length optional AJ field
     * @param  string $nbDateDue  value for the due date portion of the fixed length field
     * @param  string $itmProp    value for the variable length optional CH field
     * @param  string $fee        value for the variable length optional BO field
     * @param  string $noBlock    value for the blocking portion of the fixed length field
     * @param  string $thirdParty value for the party section of the fixed length field
     * @return string             SIP2 request message
     * @api
     */
    function msgRenew($item = '', $title = '', $nbDateDue = '', $itmProp = '', $fee= 'N', $noBlock = 'N', $thirdParty = 'N') 
    {
        /* renew a single item (29) - untested */
        $this->_newMessage('29');
        $this->_addFixedOption($thirdParty, 1);
        $this->_addFixedOption($noBlock, 1);
        $this->_addFixedOption($this->_datestamp(), 18);
        if ($nbDateDue != '') {
            /* override default date due */
            $this->_addFixedOption($this->_datestamp($nbDateDue), 18);
        } else {
            /* send a blank date due to allow ACS to use default date due computed for item */
            $this->_addFixedOption('', 18);
        }
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AA',$this->patron);
        $this->_addVarOption('AD',$this->patronpwd, true);
        $this->_addVarOption('AB',$item, true);
        $this->_addVarOption('AJ',$title, true);
        $this->_addVarOption('AC',$this->AC, true);
        $this->_addVarOption('CH',$itmProp, true);
        $this->_addVarOption('BO',$fee, true); /* Y or N */

        return $this->_returnMessage();
    }

    /**
     * Generate Renew All (code 65) request messages in sip2 format
     * @param  string $fee value for the optional variable length BO field
     * @return string      SIP2 request message
     * @api
     */
    function msgRenewAll($fee = 'N') 
    {
        /* renew all items for a patron (65) - untested */
        $this->_newMessage('65');
        $this->_addVarOption('AO',$this->AO);
        $this->_addVarOption('AA',$this->patron);
        $this->_addVarOption('AD',$this->patronpwd, true);
        $this->_addVarOption('AC',$this->AC, true);
        $this->_addVarOption('BO',$fee, true); /* Y or N */

        return $this->_returnMessage();
    }

    /**
     * Parse the response returned from Patron Status request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parsePatronStatusResponse($response) 
    {
        $result['fixed'] = 
        array( 
        'PatronStatus'      => substr($response, 2, 14),
        'Language'          => substr($response, 16, 3),
        'TransactionDate'   => substr($response, 19, 18),
        );    

        $result['variable'] = $this->_parsevariabledata($response, 37);
        return $result;
    }

    /**
     * Parse the response returned from Checkout request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parseCheckoutResponse($response) 
    {
        $result['fixed'] = 
        array( 
        'Ok'                => substr($response,2,1),
        'RenewalOk'         => substr($response,3,1),
        'Magnetic'          => substr($response,4,1),
        'Desensitize'       => substr($response,5,1),
        'TransactionDate'   => substr($response,6,18),
        );
        
        $result['variable'] = $this->_parsevariabledata($response, 24);
        return $result;
    }

    /**
     * Parse the response returned from Checkin request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parseCheckinResponse($response) 
    {
        $result['fixed'] = 
        array( 
        'Ok'                => substr($response,2,1),
        'Resensitize'       => substr($response,3,1),
        'Magnetic'          => substr($response,4,1),
        'Alert'             => substr($response,5,1),
        'TransactionDate'   => substr($response,6,18),
        );
        
        $result['variable'] = $this->_parsevariabledata($response, 24);
        return $result;
    }

    /**
     * Parse the response returned from SC Status request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parseACSStatusResponse($response) 
    {
        $result['fixed'] = 
        array( 
        'Online'            => substr($response, 2, 1),
        'Checkin'           => substr($response, 3, 1),  /* is Checkin by the SC allowed ?*/
        'Checkout'          => substr($response, 4, 1),  /* is Checkout by the SC allowed ?*/
        'Renewal'           => substr($response, 5, 1),  /* renewal allowed? */
        'PatronUpdate'      => substr($response, 6, 1),  /* is patron status updating by the SC allowed ? (status update ok)*/
        'Offline'           => substr($response, 7, 1),
        'Timeout'           => substr($response, 8, 3),
        'Retries'           => substr($response, 11, 3), 
        'TransactionDate'   => substr($response, 14, 18),
        'Protocol'          => substr($response, 32, 4),
        );

        $result['variable'] = $this->_parsevariabledata($response, 36);
        return $result;
    }

    /**
     * Parse the response returned from login request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parseLoginResponse($response) 
    {
        $result['fixed'] = 
        array( 
        'Ok'                => substr($response, 2, 1),
        );
        $result['variable'] = array();
        return $result;
    }

    /**
     * Parse the response returned from Patron Information request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parsePatronInfoResponse($response) 
    {
        $result['fixed'] = 
        array( 
        'PatronStatus'      => substr($response, 2, 14),
        'Language'          => substr($response, 16, 3),
        'TransactionDate'   => substr($response, 19, 18),
        'HoldCount'         => intval (substr($response, 37, 4)),
        'OverdueCount'      => intval (substr($response, 41, 4)),
        'ChargedCount'      => intval (substr($response, 45, 4)),
        'FineCount'         => intval (substr($response, 49, 4)),
        'RecallCount'       => intval (substr($response, 53, 4)),
        'UnavailableCount'  => intval (substr($response, 57, 4))
        );    

        $result['variable'] = $this->_parsevariabledata($response, 61);
        return $result;
    }

    /**
     * Parse the response returned from End Session request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parseEndSessionResponse($response) 
    {
        /*   Response example:  36Y20080228 145537AOWOHLERS|AAX00000000|AY9AZF474   */
        $result['fixed'] = 
        array( 
        'EndSession'        => substr($response, 2, 1),
        'TransactionDate'   => substr($response, 3, 18),
        );

        $result['variable'] = $this->_parsevariabledata($response, 21);
        return $result;
    }

    /**
     * Parse the response returned from Fee Paid request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parseFeePaidResponse($response) 
    {
        $result['fixed'] = 
        array(
        'PaymentAccepted'   => substr($response, 2, 1),
        'TransactionDate'   => substr($response, 3, 18),
        );

        $result['variable'] = $this->_parsevariabledata($response, 21);
        return $result;
    }

    /**
     * Parse the response returned from Item Info request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parseItemInfoResponse($response) 
    {
        $result['fixed'] = 
        array( 
        'CirculationStatus' => intval (substr($response, 2, 2)),
        'SecurityMarker'    => intval (substr($response, 4, 2)),
        'FeeType'           => intval (substr($response, 6, 2)),
        'TransactionDate'   => substr($response, 8, 18),
        );

        $result['variable'] = $this->_parsevariabledata($response, 26);

        return $result;
    }

    /**
     * Parse the response returned from Item Status request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parseItemStatusResponse($response) 
    {
        $result['fixed'] = 
        array( 
        'PropertiesOk'      => substr($response, 2, 1),
        'TransactionDate'   => substr($response, 3, 18),
        );

        $result['variable'] = $this->_parsevariabledata($response, 21);
        return $result;
        
    }

    /**
     * Parse the response returned from Patron Enable request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parsePatronEnableResponse($response) 
    {
        $result['fixed'] = 
        array( 
        'PatronStatus'      => substr($response, 2, 14),
        'Language'          => substr($response, 16, 3),
        'TransactionDate'   => substr($response, 19, 18),
        );

        $result['variable'] = $this->_parsevariabledata($response, 37);
        return $result;
    }

    /**
     * Parse the response returned from Hold request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parseHoldResponse($response) 
    {
        $result['fixed'] = 
        array( 
        'Ok'                => substr($response, 2, 1),
        'available'         => substr($response, 3, 1),
        'TransactionDate'   => substr($response, 4, 18),
        'ExpirationDate'    => substr($response, 22, 18)
        );

        $result['variable'] = $this->_parsevariabledata($response, 40);
        return $result;
    }

    /**
     * Parse the response returned from Renew request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parseRenewResponse($response) 
    {
        /* Response Example:  300NUU20080228    222232AOWOHLERS|AAX00000241|ABM02400028262|AJFolksongs of Britain and Ireland|AH5/23/2008,23:59|CH|AFOverride required to exceed renewal limit.|AY1AZCDA5 */
        $result['fixed'] = 
        array( 
        'Ok'                => substr($response, 2, 1),
        'RenewalOk'         => substr($response, 3, 1),
        'Magnetic'          => substr($response, 4, 1),
        'Desensitize'       => substr($response, 5, 1),
        'TransactionDate'   => substr($response, 6, 18),
        );

        $result['variable'] = $this->_parsevariabledata($response, 24);
        return $result;
    }

    /**
     * Parse the response returned from Renew All request messages
     * @param  string $response response string from the SIP2 backend
     * @return array            parsed SIP2 response message
     * @api
     */
    function parseRenewAllResponse($response) 
    {
        $result['fixed'] = 
        array( 
        'Ok'                => substr($response, 2, 1),
        'Renewed'           => substr($response, 3, 4),
        'Unrenewed'         => substr($response, 7, 4),
        'TransactionDate'   => substr($response, 11, 18),
        );

        $result['variable'] = $this->_parsevariabledata($response, 29);
        return $result;
    }

    /**
     * Send a message to the backend SIP2 system and read response
     * @param  string $message The message text to send to the backend system (request)
     * @return string|false    Raw string response returned from the backend system (response)
     * @api
     */
    function get_message ($message) 
    {
        /* sends the current message, and gets the response */
        $result     = '';
        $terminator = '';
        $nr         = '';

        $this->_debugmsg('SIP2: Sending SIP2 request...');
        fwrite($this->socket, $message, strlen($message));

        $this->_debugmsg('SIP2: Request Sent, Reading response');

        while ($terminator != "\x0D" && $nr !== FALSE) {
            $terminator = fread($this->socket, 1);
            $result = $result . $terminator;
        }

        $this->_debugmsg("SIP2: {$result}");

        /* test message for CRC validity */
        if ($this->_check_crc($result)) {
            /* reset the retry counter on successful send */
            $this->retry=0;
            $this->_debugmsg("SIP2: Message from ACS passed CRC check");
        } else {
            /* CRC check failed, request a resend */
            $this->retry++;
            if ($this->retry < $this->maxretry) {
                /* try again */
                $this->_debugmsg("SIP2: Message failed CRC check, retrying ({$this->retry})");
                
                $this->get_message($message);
            } else {
                /* give up */
                $this->_debugmsg("SIP2: Failed to get valid CRC after {$this->maxretry} retries.");
                return false;
            }
        }
        return $result;
    }

    /**
     * Open a socket connection to a backend SIP2 system
     * @return bool The socket connection status
     * @api
     */
    function connect() 
    {

        /* Socket Communications  */
        $this->_debugmsg( "SIP2: --- BEGIN SIP communication ---");  
        
        /* Get the IP address for the target host. */
        $address = gethostbyname($this->hostname);

        $this->_debugmsg( "SIP2: Attempting to connect to '$address' on port '{$this->port}'..."); 

        $this->socket = stream_socket_client("tcp://$address:$this->port", $errno, $errstr, 30);
        if(!$this->socket) {
            $this->_debugmsg( "SIP2: stream_socket_client() failed: reason: $errstr");
        }

        return true;

        
    }

    /**
     * Disconnect from the backend SIP2 system (close socket)
     * @api
     */
    function disconnect () 
    {
        /*  Close the socket */
        fclose($this->socket);
    }


    /* internal utillity functions */

    /**
     * Generate a SIP2 compatable datestamp
     * From the spec:
     * YYYYMMDDZZZZHHMMSS. 
     * All dates and times are expressed according to the ANSI standard X3.30 for date and X3.43 for time. 
     * The ZZZZ field should contain blanks (code $20) to represent local time. To represent universal time, 
     *  a Z character(code $5A) should be put in the last (right hand) position of the ZZZZ field. 
     * To represent other time zones the appropriate character should be used; a Q character (code $51) 
     * should be put in the last (right hand) position of the ZZZZ field to represent Atlantic Standard Time. 
     * When possible local time is the preferred format.
     *
     * @param  $timestamp  unix timestamp to format (default use current time)
     * @return string      a SIP2 compatible date/time stamp
     * @internal
     */
    function _datestamp($timestamp = '') 
    {
        if ($timestamp != '') {
            /* Generate a proper date time from the date provided */
            return date('Ymd    His', $timestamp);
        } else {
            /* Current Date/Time */
            return date('Ymd    His');
        }
    }

    /**
     * Parse variable length fields from SIP2 responses
     * @param  string $response [description]
     * @param  int    $start    [description]
     * @return array            an array containing the parsed variable length data fields
     * @internal
     */
    function _parsevariabledata($response, $start) 
    {
        $result = array();
        $response = trim($response);
        if ($this->withCrc) {
            $result['Raw'] = explode("|", substr($response,$start,-6));
        }
        else {
            $result['Raw'] = explode("|", substr($response, $start));
        }
        foreach ($result['Raw'] as $item) {
            $field = substr($item,0,2);
            $value = substr($item,2);
            /**
            * SD returns some odd values on ocassion, Unable to locate the purpose in spec, so I strip from 
            * the parsed array. Orig values will remain in ['raw'] element
            */
            $clean = trim($value, "\x00..\x1F");
            if (trim($clean) <> '') {
                $result[$field][] = $clean;
            }
        }
        if ($this->withCrc) {
            $result['AZ'][] = substr($response,-4);
        }
        else {
            $result['AZ'] = array();
        }
        return ($result);
    }

    /**
     * Generate and format checksums for SIP2 messages
     * @param  string $string the string to checksum
     * @return string         properly formatted checksum of given string
     * @internal
     */
    function _crc($string) 
    {
        /* Calculate CRC  */
        $sum = 0;

        $len = strlen($string);
        for ($n = 0; $n < $len; $n++) {
            $sum = $sum + ord(substr($string, $n, 1));
        } 

        $crc = ($sum & 0xFFFF) * -1;

        /* 2008.03.15 - Fixed a bug that allowed the checksum to be larger then 4 digits */
        return substr(sprintf ("%4X", $crc), -4, 4);
    }

    /**
     * Manage the internal sequence number and return the next in the list
     * @return int internal sequence number
     * @internal
     */
    function _getseqnum() 
    {
        /* Get a sequence number for the AY field */
        /* valid numbers range 0-9 */
        $this->seq++;
        if ($this->seq > 9 ) {
            $this->seq = 0;
        }
        return ($this->seq);
    }

    /**
     * Handle the printing of debug messages
     * @param  string $message the message text
     * @internal
     */
    function _debugmsg($message) 
    {
        /* custom debug function,  why repeat the check for the debug flag in code... */
        if ($this->debug) { 
            trigger_error( $message, E_USER_NOTICE); 
        }
    }

    /**
     * Verify the integrity of SIP2 messages containing checksums
     * @param  string $message the messsage to check
     * @return bool
     * @internal
     */
    function _check_crc($message) 
    {
        /* check for enabled crc */
        if ($this->withCrc !== true) return true;

        /* test the recieved message's CRC by generating our own CRC from the message */
        $test = preg_split('/(.{4})$/',trim($message),2,PREG_SPLIT_DELIM_CAPTURE);

        /* check validity */
        if (isset($test[0]) && isset($test[1]) && strcmp($this->_crc($test[0]), $test[1]) == 0) {
            return true;
        }

        /* default return */
        return false;
    }

    /**
     * Reset the internal message buffers and start a new message
     * @param  int $code  The message code to start the string
     * @internal
     */
    function _newMessage($code) 
    {
        /* resets the msgBuild variable to the value of $code, and clears the flag for fixed messages */
        $this->noFixed  = false;
        $this->msgBuild = $code;
    }

    /**
     * Add a fixed length option field to a request message
     * @param string $value the option value
     * @param int    $len   the length of the option field
     * @return bool
     * @internal
     */
    function _addFixedOption($value, $len) 
    {
        /* adds afixed length option to the msgBuild IF no variable options have been added. */
        if ( $this->noFixed ) {
            return false;
        } else {
            $this->msgBuild .= sprintf("%{$len}s", substr($value,0,$len));
            return true;
        }
    }

    /**
     * Add a variable length option field to a request message
     * @param string  $field    field code for this message
     * @param string  $value    the option vaule
     * @param bool    $optional optional field designation (default false)
     * @return bool
     * @internal
     */
    function _addVarOption($field, $value, $optional = false) 
    {
        /* adds a varaiable length option to the message, and also prevents adding addtional fixed fields */
        if ($optional == true && $value == '') {
            /* skipped */
            $this->_debugmsg( "SIP2: Skipping optional field {$field}");
        } else {
            $this->noFixed  = true; /* no more fixed for this message */
            $this->msgBuild .= $field . substr($value, 0, 255) . $this->fldTerminator;
        }
        return true;
    }

    /**
     * Return the contents of the internal msgBuild variable after appending
     * sequence and crc fields if requested and appending terminators
     * @param  bool $withSeq optional value to enforce addition of sequence numbers
     * @param  bool $withCrc optional value to enforce addition of CRC checks
     * @return string        formatted sip2 message text complete with termination
     * @internal
     */
    function _returnMessage($withSeq = null, $withCrc = null) 
    {
        /* use object defaults if not passed */
        $withSeq = empty($withSeq) ? $this->withSeq : $withSeq;
        $withCrc = empty($withCrc) ? $this->withCrc : $withCrc;

        /* Finalizes the message and returns it.  Message will remain in msgBuild until newMessage is called */
        if ($withSeq) {
            $this->msgBuild .= 'AY' . $this->_getseqnum();
        }
        if ($withCrc) {
            $this->msgBuild .= 'AZ';
            $this->msgBuild .= $this->_crc($this->msgBuild);
        }
        $this->msgBuild .= $this->msgTerminator;

        return $this->msgBuild;
    }
}
