<?php

include 'sip2.class.php';
class Sip2Wrapper {
    protected $_sip2 = NULL;
    protected $_connected = false;
    protected $_selfChecked = false;
    protected $_inPatronSession = false;
    protected $_patronStatus = NULL;
    protected $_patronInfo = NULL;
    protected $_acsStatus = NULL;

    public function __get($name) {
        $functionName = 'get'.ucfirst($name);
        if (method_exists($this, $functionName)) {
            return call_user_func(array($this, $functionName));
        }
        throw new Exception('Undefined parameter '.$name);
    }

    public function getSip2() {
        return $this->_sip2;
    }
    
    public function getPatronStatus() {
        if (!$this->_inPatronSession) {
            throw new Exception('Must start patron session before calling getPatronStatus');
        }
        if ($this->_patronStatus === NULL) {
            $this->fetchPatronStatus();
        }

        return $this->_patronStatus;
    }

    public function getPatronIsValid() {
        $patronStatus = $this->getPatronStatus();
        if (strcmp($patronStatus['variable']['BL'][0], 'Y') !== 0 || strcmp($patronStatus['variable']['CQ'][0], 'Y') !== 0) {
            return false;
        }
        return true;
    }
    
    public function getPatronFinesTotal() {
        $status = $this->getPatronStatus();
        if (isset($status['variable']['BV'][0])) {
            return (float)$status['variable']['BV'][0];
        }
        return 0.00;
    }

    public function getPatronScreenMessages() {
        $status = $this->getPatronStatus();
        if (isset($status['variable']['AF']) && is_array($status['variable']['AF'])) {
            return $status['variable']['AF'];
        }
        else {
            return array();
        }
    }
    
    public function getPatronHoldItems() {
        $info = $this->fetchPatronInfo('hold');
        if (isset($info['variable']['AS'])) {
            return $info['variable']['AS'];
        }
        return array();
    }

    public function getPatronOverdueItems() {
        $info = $this->fetchPatronInfo('overdue');
        if (isset($info['variable']['AT'])) {
            return $info['variable']['AT'];
        }
        return array();
    }

    public function getPatronChargedItems() {
        $info = $this->fetchPatronInfo('charged');
        if (isset($info['variable']['AU'])) {
            return $info['variable']['AU'];
        }
        return array();
    }

    public function getPatronFineItems() {
        $info = $this->fetchPatronInfo('fine');
        if (isset($info['variable']['AV'])) {
            return $info['variable']['AV'];
        }
        return array();
    }

    
    public function getPatronRecallItems() {
        $info = $this->fetchPatronInfo('recall');
        if (isset($info['variable']['BU'])) {
            return $info['variable']['BU'];
        }
        return array();
    }
    
    public function getPatronUnavailableItems() {
        $info = $this->fetchPatronInfo('unavail');
        if (isset($info['variable']['CD'])) {
            return $info['variable']['CD'];
        }
        return array();
    }

    public function fetchPatronInfo($type = 'none') {
        if (!$this->_inPatronSession) {
            throw new Exception('Must start patron session before calling fetchPatronInfo');
        }
        if (is_array($this->_patronInfo) && isset($this->_patronInfo[$type])) {
            return $this->_patronInfo[$type];
        }
        $msg = $this->_sip2->msgPatronInformation($type);
        $info_response = $this->_sip2->parsePatronInfoResponse($this->sip2->get_message($msg));
        if ($this->_patronInfo === NULL) {
            $this->_patronInfo = array();
        }
        $this->_patronInfo[$type] = $info_response;
        return $info_response;
    }

    public function getAcsStatus() {
        return $this->_acsStatus;
    }

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
        return true;
    }
    
    public function selfCheck() {
        /* execute self test */
        $msg = $this->_sip2->msgSCStatus();
        $status = $this->_sip2->parseACSStatusResponse($this->_sip2->get_message($msg));
        $this->_acsStatus = $status;
        /* check status */
        if (strcmp($status['fixed']['Online'], 'Y') !== 0) {
            throw new Exception('ACS Offline');
        }

        return true;
    }
    
    public function startPatronSession($patronId, $patronPass) {
        if ($this->_inPatronSession) {
            $this->endPatronSession();
            $this->_inPatronSession = false;
        }
        $this->_sip2->patron = $patronId;
        $this->_sip2->patronpwd = $patronPass;
        if($this->getPatronIsValid()) {
            $this->_inPatronSession = true;
        }
        return $this->_inPatronSession;
    }

    public function fetchPatronStatus() {
        $msg = $this->_sip2->msgPatronStatusRequest();
        $patron = $this->_sip2->parsePatronStatusResponse($this->_sip2->get_message($msg));
        $this->_patronStatus = $patron;
        /* check for valid credentials */
        return true;
    }

    public function endPatronSession() {
        $msg = $this->_sip2->msgEndPatronSession();
        $end = $this->_sip2->parseEndSessionResponse($this->_sip2->get_message($msg));
        if (strcmp($end['fixed']['EndSession'], 'Y') !== 0) {
            throw new Exception('Error ending patron session');
        }
        $this->_inPatronSession = false;
        $this->_patronStatus = NULL;
        $this->_patronInfo = NULL;
    }
    
    public function disconnect() {
        $this->_sip2->disconnect();
        $this->_connected = false;
        $this->_inPatronSession = false;
        $this->_patronInfo = NULL;
        $this->_acsStatus = NULL;
    }
}