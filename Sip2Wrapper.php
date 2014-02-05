<?php

require_once 'sip2.class.php';


/**
 *
 * @author nathan@nathanjohnson.info
 */

/**
 * This is a wrapper class for the sip2.class.php from google code
 *
 * usage:
 *
 * require_once 'Sip2Wrapper.php';
 * $sip2 = new Sip2Wrapper(
 *  array(
 *      'hostname' => $hostname,
 *      'port' => 6001,
 *      'withCrc' => false,
 *      'location' => $location,
 *      'institutionId' => $institutionId
 *  )
 * );

 * $sip2->login($user, $pass);

 * if ($sip2->startPatronSession($patron, $patronpwd)) {
 *   var_dump($sip2->patronScreenMessages);
 * }
 *
 */

class Sip2Wrapper {

    /**
     *
     * protected variables, accessible read-only via magic getter method
     * For instance, to get a copy of $_sip2, you can call $obj->sip2
     */
    protected $_sip2 = NULL;
    protected $_connected = false;
    protected $_selfChecked = false;
    protected $_inPatronSession = false;
    protected $_patronStatus = NULL;
    protected $_patronInfo = NULL;
    protected $_acsStatus = NULL;


    /**
     *
     * @param string $name - the member variable name
     * @throws Exception
     * @return mixed
     */
    public function __get($name) {
        /* look for a getter function named getName */
        $functionName = 'get'.ucfirst($name);
        if (method_exists($this, $functionName)) {
            return call_user_func(array($this, $functionName));
        }
        throw new Exception('Undefined parameter '.$name);
    }

    /**
     * getter function for $this->_sip2
     * @return sip2
     */

    public function getSip2() {
        return $this->_sip2;
    }


    /**
     *
     * @throws Exception if patron session hasn't began
     * @return array the patron status
     */
    public function getPatronStatus() {
        if (!$this->_inPatronSession) {
            throw new Exception('Must start patron session before calling getPatronStatus');
        }
        if ($this->_patronStatus === NULL) {
            $this->fetchPatronStatus();
        }

        return $this->_patronStatus;
    }


    /**
     * parses patron status to determine if login was successful.
     * @return boolean returns true if valid, false otherwise
     */
    public function getPatronIsValid() {
        $patronStatus = $this->getPatronStatus();
        if (strcmp($patronStatus['variable']['BL'][0], 'Y') !== 0 || strcmp($patronStatus['variable']['CQ'][0], 'Y') !== 0) {
            return false;
        }
        return true;
    }

    /**
     * Returns the total fines from patron status call
     * @return number the float value of the fines
     */
    public function getPatronFinesTotal() {
        $status = $this->getPatronStatus();
        if (isset($status['variable']['BV'][0])) {
            return (float)$status['variable']['BV'][0];
        }
        return 0.00;
    }

    /**
     * returns the Screen Messages field of the patron status, which can include
     * for example blocked or barred
     *
     * @return array the screen messages
     */

    public function getPatronScreenMessages() {
        $status = $this->getPatronStatus();
        if (isset($status['variable']['AF']) && is_array($status['variable']['AF'])) {
            return $status['variable']['AF'];
        }
        else {
            return array();
        }
    }

    /**
     * gets the patron info hold items field
     * @return array Hold Items
     */
    public function getPatronHoldItems() {
        $info = $this->fetchPatronInfo('hold');
        if (isset($info['variable']['AS'])) {
            return $info['variable']['AS'];
        }
        return array();
    }

    /**
     * Get the patron info overdue items field
     * @return array overdue items
     */
    public function getPatronOverdueItems() {
        $info = $this->fetchPatronInfo('overdue');
        if (isset($info['variable']['AT'])) {
            return $info['variable']['AT'];
        }
        return array();
    }

    /**
     * get the charged items field
     * @return array charged items
     */

    public function getPatronChargedItems() {
        $info = $this->fetchPatronInfo('charged');
        if (isset($info['variable']['AU'])) {
            return $info['variable']['AU'];
        }
        return array();
    }

    /**
     * return patron fine detail from patron info
     * @return array fines
     */
    public function getPatronFineItems() {
        $info = $this->fetchPatronInfo('fine');
        if (isset($info['variable']['AV'])) {
            return $info['variable']['AV'];
        }
        return array();
    }

    /**
     * return patron recall items from patron info
     * @return array patron items
     */
    public function getPatronRecallItems() {
        $info = $this->fetchPatronInfo('recall');
        if (isset($info['variable']['BU'])) {
            return $info['variable']['BU'];
        }
        return array();
    }


    /**
     * return patron unavailable items from patron info
     * @return array unavailable items
     */

    public function getPatronUnavailableItems() {
        $info = $this->fetchPatronInfo('unavail');
        if (isset($info['variable']['CD'])) {
            return $info['variable']['CD'];
        }
        return array();
    }

    /**
     * worker function to call out to sip2 server and grab patron information.
     * @param string $type One of 'none', 'hold', 'overdue', 'charged', 'fine', 'recall', or 'unavail'
     * @throws Exception if startPatronSession has not been called with success prior to calling this
     * @return array the parsed response from the server
     */
    public function fetchPatronInfo($type = 'none') {
        if (!$this->_inPatronSession) {
            throw new Exception('Must start patron session before calling fetchPatronInfo');
        }
        if (is_array($this->_patronInfo) && isset($this->_patronInfo[$type])) {
            return $this->_patronInfo[$type];
        }
        $msg = $this->_sip2->msgPatronInformation($type);
        $info_response = $this->_sip2->parsePatronInfoResponse($this->_sip2->get_message($msg));
        if ($this->_patronInfo === NULL) {
            $this->_patronInfo = array();
        }
        $this->_patronInfo[$type] = $info_response;
        return $info_response;
    }
    /**
     * getter for acsStatus
     * @return Ambigous <NULL, multitype:string multitype:multitype:  >
     */
    public function getAcsStatus() {
        return $this->_acsStatus;
    }

    /**
     * constructor
     * @param $sip2Params array of key value pairs that will set the corresponding member variables
     * in the underlying sip2 class
     * @param boolean $autoConnect whether or not to automatically connect to the server.  defaults
     * to true
     */
    public function __construct($sip2Params = array(), $autoConnect = true) {
        $sip2 = new sip2;
        foreach ($sip2Params as $key => $val) {
            switch($key) {
                case 'institutionId':
                    $key = 'AO';
                    break;
                case 'location':
                    $key = 'scLocation';
                    break;
            }
            if (property_exists($sip2, $key)) {
                $sip2->$key = $val;
            }
        }
        $this->_sip2 = $sip2;
        if ($autoConnect) {
            $this->connect();
        }
    }

    /**
     * Connect to the server
     * @throws Exception if connection fails
     * @return boolean returns true if connection succeeds
     */
    public function connect() {
        $returnVal = $this->_sip2->connect();
        if ($returnVal === true) {
            $this->_connected = true;
        }
        else {
            throw new Exception('Connection failed');
        }
        return true;
    }

    /**
     * authenticate with admin credentials to the backend server
     * @param string $bindUser The admin user
     * @param unknown $bindPass The admin password
     * @param string $autoSelfCheck Whether to call SCStatus after login.  Defaults to true
     * you probably want this.
     * @throws Exception if login failed
     * @return Sip2Wrapper - returns $this if login successful
     */
    public function login($bindUser, $bindPass, $autoSelfCheck=true) {
        $msg = $this->_sip2->msgLogin($bindUser, $bindPass);
        $login = $this->_sip2->parseLoginResponse($this->_sip2->get_message($msg));
        if ((int) $login['fixed']['Ok'] !== 1) {
            throw new Exception('Login failed');
        }
        /* perform self check */
        if ($autoSelfCheck) {
            $this->selfCheck();
        }
        return $this;
    }
    /**
     * Checks the ACS Status to ensure that the ACS is online
     * @throws Exception if ACS is not online
     * @return Sip2Wrapper returns $this if successful
     */
    public function selfCheck() {

        /* execute self test */
        $msg = $this->_sip2->msgSCStatus();
        $status = $this->_sip2->parseACSStatusResponse($this->_sip2->get_message($msg));
        $this->_acsStatus = $status;
        /* check status */
        if (strcmp($status['fixed']['Online'], 'Y') !== 0) {
            throw new Exception('ACS Offline');
        }

        return $this;
    }

    /**
     * This method is required before any get/fetch methods that have Patron in the name.  Upon
     * successful login, it sets the inPatronSession property to true, otherwise false.
     * @param string $patronId Patron login ID
     * @param string $patronPass Patron password
     * @return boolean returns true on successful login, false otherwise
     */
    public function startPatronSession($patronId, $patronPass) {
        if ($this->_inPatronSession) {
            $this->endPatronSession();
        }
        $this->_sip2->patron = $patronId;
        $this->_sip2->patronpwd = $patronPass;

        // set to true before call to getPatronIsValid since it will throw an exception otherwise
        $this->_inPatronSession = true;
        $this->_inPatronSession = $this->getPatronIsValid();
        return $this->_inPatronSession;
    }

    /**
     * method to grab the patron status from the server and store it in _patronStatus
     * @return Sip2Wrapper returns $this
     */
    public function fetchPatronStatus() {
        $msg = $this->_sip2->msgPatronStatusRequest();
        $patron = $this->_sip2->parsePatronStatusResponse($this->_sip2->get_message($msg));
        $this->_patronStatus = $patron;
        return $this;
    }

    /**
     * method to send a patron session to the server
     * @throws Exception if patron session is not properly ended
     * @return Sip2Wrapper returns $this
     */
    public function endPatronSession() {
        $msg = $this->_sip2->msgEndPatronSession();
        $end = $this->_sip2->parseEndSessionResponse($this->_sip2->get_message($msg));
        if (strcmp($end['fixed']['EndSession'], 'Y') !== 0) {
            throw new Exception('Error ending patron session');
        }
        $this->_inPatronSession = false;
        $this->_patronStatus = NULL;
        $this->_patronInfo = NULL;
        return $this;
    }

    /**
     * disconnect from the server
     * @return Sip2Wrapper returns $this
     */
    public function disconnect() {

        $this->_sip2->disconnect();
        $this->_connected = false;
        $this->_inPatronSession = false;
        $this->_patronInfo = NULL;
        $this->_acsStatus = NULL;
        return $this;
    }
}