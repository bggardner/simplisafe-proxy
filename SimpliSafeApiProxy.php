<?php
/**
 * A pseduo-proxy for the SimpliSafe API
 */
class SimpliSafeApiProxy
{
    /** @var string API_BASE_URL Base URL of the SimpliSafe API */
    public const API_BASE_URL = 'https://api.simplisafe.com/v1';

    /** @var string MEDIA_BASE_URL Base URL of the SimpliSafe media server */
    public const MEDIA_BASE_URL = 'https://media.simplisafe.com/v1';

    /** @var array WEBAPP_HEADERS Headers used by SimpliSafe WebApp (probably only need 'Accept') */
    public const WEBAPP_HEADERS = array(
        'Origin: https://webapp.simplisafe.com',
        'Referer: https://webapp.simplisafe.com/',
        'Accept: application/json, text/plain, */*',
        'Accept-Encoding: gzip, deflate, br',
        'Accept-Language: en-US,en;q=0.5'
    );

    /** @var string WEBAPP_URL URL of the WebApp */
    public const WEBAPP_URL = 'https://webapp.simplisafe.com';

    /** @var string ALARM_STATE_OFF Value used to set alarm state */
    public const ALARM_STATE_OFF = 'off';
    /** @var string ALARM_STATE_HOME Value used to set alarm state */
    public const ALARM_STATE_HOME = 'home';
    /** @var string ALARM_STATE_AWAY Value used to set alarm state */
    public const ALARM_STATE_AWAY = 'away';
    /** @var array ALARM_STATES Values used to set alarm state */
    public const ALARM_STATES = array(self::ALARM_STATE_OFF, self::ALARM_STATE_HOME, self::ALARM_STATE_AWAY);

    /** @var object|null $token The authorization token received after authenticating */
    protected $token;

    /** @var object|null $token The user object of the authenticated user */
    protected $user;

    /**
     * @param string $username SimpliSafe account username
     * @param string $password SimpliSafe account password
     * @param string $deviceId Name that will be displayed in the 'Recenlty Used Mobile Devices' log
     */
    function __construct($username, $password, $deviceId = 'SimpliSafeApiProxy')
    {
      $this->token = self::getToken($username, $password, $deviceId);
      $this->user = $this->getUser();
      $this->subscription = $this->getSubscriptions()[0];
    }

    /**
     * Look up HTTP authentication "user" for requesting token (derived from HTML comment in WebApp)
     *
     * @return string
     */
    public static function getAuthorizationUser()
    {
        $curlopts = array(
            CURLOPT_URL => self::WEBAPP_URL,
            CURLOPT_HTTPHEADER => SELF::WEBAPP_HEADERS,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_RETURNTRANSFER => true
        );
        $curlopts[CURLOPT_HTTPHEADER][] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
        $ch = curl_init();
        curl_setopt_array($ch, $curlopts);
        $response = curl_exec($ch);
        curl_close($ch);
        preg_match('/<!-- Version (.+) \| (.+) -->/', $response, $matches);
        $uuid = $matches[2];
        $auth_user = $uuid . '.' . str_replace('.', '-', $matches[1]) . '.WebApp.simplisafe.com';
        return $auth_user;
    }

    /**
     * Gets access token object via SimpliSafe API
     *
     * @param string $username SimpliSafe account username
     * @param string $password SimpliSafe account password
     * @param string $deviceId Name that will be displayed in the 'Recenlty Used Mobile Devices' log
     *               WebApp uses 'Webapp; useragent="<user-agent>"; uuid="<uuid>"
     *
     * @return object
     */
    public static function getToken($username, $password, $deviceId = 'SimpliSafeApiProxy')
    {
        $auth_user = self::getAuthorizationUser();
        $curlopts = array(
            CURLOPT_URL => self::API_BASE_URL . '/api/token',
            CURLOPT_HTTPHEADER => SELF::WEBAPP_HEADERS,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $auth_user . ':',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(array(
                'grant_type' => 'password',
                'username' => $username,
                'password' => $password,
                'device_id' => $deviceId
            )),
        );
        $curlopts[CURLOPT_HTTPHEADER][] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
        $curlopts[CURLOPT_HTTPHEADER][] = 'Content-Length: ' . strlen($curlopts[CURLOPT_POSTFIELDS]);
        $ch = curl_init();
        curl_setopt_array($ch, $curlopts);
        $response = curl_exec($ch);
        curl_close($ch);
        $token = json_decode($response);
        return $token;
    }

    /**
      * Utility function to get a URL via the SimpliSafe API
      *
      * @param string $url URL of the requested resource
      *
      * @return object
      */
    public function get($url)
    {
        $curlopts = array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => SELF::WEBAPP_HEADERS,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_RETURNTRANSFER => true
        );
        $curlopts[CURLOPT_HTTPHEADER][] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
        $curlopts[CURLOPT_HTTPHEADER][] = 'Authorization: ' . $this->token->token_type . ' ' . $this->token->access_token;
        $ch = curl_init();
        curl_setopt_array($ch, $curlopts);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response);
    }

    /**
      * Utility function to post a URL via the SimpliSafe API
      *
      * @param string $url URL of the requested resource
      *
      * @return object
      */
    public function post($url, $data = [])
    {
        $curlopts = array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => SELF::WEBAPP_HEADERS,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data)
        );
        $curlopts[CURLOPT_HTTPHEADER][] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
        $curlopts[CURLOPT_HTTPHEADER][] = 'Content-Length: ' . strlen($curlopts[CURLOPT_POSTFIELDS]);
        $curlopts[CURLOPT_HTTPHEADER][] = 'Authorization: ' . $this->token->token_type . ' ' . $this->token->access_token;
        $ch = curl_init();
        curl_setopt_array($ch, $curlopts);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response);
    }

    /**
     * Invalidates an access token via the SimpliSafe API and destroys the object
     */
    public function invalidateToken()
    {
        $curlopts = array(
            CURLOPT_URL => self::API_BASE_URL . '/api/token',
            CURLOPT_HTTPHEADER => SELF::WEBAPP_HEADERS,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE'
        );
        $curlopts[CURLOPT_HTTPHEADER][] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
        $curlopts[CURLOPT_HTTPHEADER][] = 'Authorization: ' . $this->token->token_type . ' ' . $this->token->access_token;
        $ch = curl_init();
        curl_setopt_array($ch, $user_curlopts);
        $response = curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Gets alarm state JSON object via the SimipliSafe API
     *
     * @return object
     */
    public function getAlarmState()
    {
        switch ($this->subscription->location->system->version) {
            case 3:
                $url = self::API_BASE_URL . '/ss3/subscriptions/' . $this->subscription->sid . '/state';
                break;
            default:
                throw new Exception("Not implemented");
        }
        return $this->get($url);
    }

    /**
     * Sets the alarm state and returns the new alarm state JSON object via the SimpliSafe
     *
     * @param string $state One of 'off', 'home', 'away' (suggested use: self::ALARM_STATE_*)
     *
     * @return object
     */
    public function setAlarmState($state)
    {
        if (!in_array($state, self::ALARM_STATES)) {
          throw new Exception('Invalid alarm state');
        }
        switch ($this->subscription->location->system->version) {
            case 3:
                $url = self::API_BASE_URL . '/ss3/subscriptions/' . $this->subscription->sid . '/state/' . $state;
                break;
            default:
                throw new Exception('Not implemented');
        }
        return $this->post($url);
    }

    /**
     * Gets the alerted users JSON object via the SimpliSafe API
     *
     * @return object
     */
    public function getAlertedUsers()
    {
        return $this->get(self::API_BASE_URL . '/subscriptions//' . $this->subscription->sid . '/alertsUsers')->alertsUsers;
    }

    /**
     * Gets the Alexa integration tokens JSON object via the SimpliSafe API
     *
     * @return object
     */
    public function getAlexaIntegrationTokens()
    {
        return $this->get(self::API_BASE_URL . '/integration/alexa/' . $this->subscription->sid . '/tokens');
    }

    /**
     * Gets the August integration info JSON object via the SimpliSafe API
     *
     * @return object
     */
    public function getAugustIntegrationInfo()
    {
        return $this->get(self::API_BASE_URL . '/integration/alexa/' . $this->subscription->sid . '/info');
    }

    /**
     * Gets the UUID of a camera from the cameras stored subscription
     *
     * @parm int $index Zero-based index
     *
     * @return string
     */
    public function getCameraUuid($index) {
        return $this->subscription->location->system->cameras[$index]->uuid;
    }

    /**
     * Gets the email preferences JSON object via the SimpliSafe API
     *
     * @return object
     */
    public function getEmailPreferences()
    {
        return $this->get(self::API_BASE_URL . '/users/' . $this->subscription->uid . '/emailPreferences');
    }

    /**
     * Gets the events JSON object via the SimpliSafe API
     *
     * @param int $numEvents Number of events to return
     *
     * @return object
     */
    public function getEvents($numEvents)
    {
        return $this->get(self::API_BASE_URL . '/subscriptions/' . $this->subscription->sid . '/events?numEvents=' . $numEvents);
    }

    /**
     * Gets the Google integration tokens JSON object via the SimpliSafe API
     *
     * @return object
     */
    public function getGoogleIntegrationTokens()
    {
        return $this->get(self::API_BASE_URL . '/integration/google/' . $this->subscription->sid . '/tokens');
    }

    /**
     * Gets login into JSON object via the SimpliSafeAPI
     *
     * @return object
     */
    public function getLoginInfo()
    {
        return $this->get(self::API_BASE_URL . '/users/' . $this->subscription->uid);
    }

    /**
     * Gets the mobile devices JSON object via the SimpliSafe API
     *
     * @return object
     */
    public function getMobileDevices()
    {
        return $this->get(self::API_BASE_URL . '/users/' . $this->subscription->uid . '/mobileDevices');
    }

    /**
     * Gets the Nest integration info JSON object via the SimpliSafe API
     *
     * @return object
     */
    public function getNestIntegration()
    {
        return $this->get(self::API_BASE_URL . '/integration/nest/' . $this->subscription->sid . '/info');
    }

    /**
     * Gets the order history JSON object via the SimpliSafe API
     *
     * @param int $numOrders Number of orders to return
     *
     * @return object
     */
    public function getOrderHistory($numOrders)
    {
        return $this->get(self::API_BASE_URL . '/users/' . $this->subscription->uid . '/orderHistory?numOrders=' . $numOrders);
    }

    /**
     * Gets the payment history JSON object via the SimpliSafe API
     *
     * @param int $numInvoices Number of invoices to return
     *
     * @return object
     */
    public function getPaymentHistory($numInvoices)
    {
        return $this->get(self::API_BASE_URL . '/users/' . $this->subscription->uid . '/paymentHistory?numInvoices=' . $numInvoices);
    }

    /**
     * Gets the PINs JSON object via the SimpliSafe API
     *
     * @return object
     */
    public function getPins()
    {
        return $this->get(self::API_BASE_URL . '/ss3/subscriptions/' . $this->subscription->sid . '/settings/pins?forceUpdate=true');
    }

    /**
     * Gets sensor JSON object via the SimpliSafeAPI
     *
     * @return object
     */
    public function getSensors()
    {
        return $this->get(self::API_BASE_URL . '/ss3/subscriptions/' . $this->subscription->sid . '/sensors?forceUpdate=true');
    }

    /**
     * Gets the base station settings JSON object via the SimpliSafeAPI
     * @return object
     */
    public function getSettings()
    {
        return $this->get(self::API_BASE_URL . '/ss3/subscriptions/' . $this->subscription->sid . '/settings/normal?forceUpdate=true');
    }

    /**
     * Gets subscription JSON object via SimpliSafe API
     *
     * @return object
     */
    public function getSubscription()
    {
        return $this->get(self::API_BASE_URL . '/subscriptions/' . $this->subscription->sid)->subscriptions;
    }

    /**
     * Gets subscriptions JSON object via SimpliSafe API
     *
     * @return object
     */
    public function getSubscriptions()
    {
        return $this->get(self::API_BASE_URL . '/users/' . $this->user->userId . '/subscriptions?activeOnly=false')->subscriptions;
    }

    /**
     * Gets user JSON object via SimpliSafe API
     *
     * @return object
     */
    public function getUser()
    {
        return $this->get(self::API_BASE_URL . '/api/authCheck');
    }

    /**
     * Gets addresses JSON object for a given user via SimpliSafe API
     *
     * @return object
     */
    public function getUserAddresses()
    {
        return $this->get(self::API_BASE_URL . '/users/' . $this->subscription->uid . '/addresses');
    }

    /**
     * Streams the camera video to the output
     *
     * @param string|int $uuid UUID of the camera (string) or zero-based index (int or numeric string)
     * @param string $format Video stream format, one of: 'flv' (Flash Video) or 'mjpg' (Motion JPEG)
     * @param string $width Width in pixels of the returned stream (clientWidth property of video element, e.g.)
     * @param string $mimeType Desired MIME type for use in the 'Content-type' header. Suggested values:
     *                             'application/octet-stream' (used with SimpliSafe Flash Player)
     *                             'video/x-flv' for $format = 'flv' (Flash Video)
     *                             'video/x-motion-jpeg' for $format = 'mjpg' (Motion JPEG)
     */
    public function streamCamera($uuid, $format, $width, $mimeType = 'application/octet-stream') {
        if (is_numeric($uuid)) {
            $uuid = $this->getCameraUuid($uuid);
        }
        header('Content-type: ' . $mimeType);
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => self::MEDIA_BASE_URL . '/' . $uuid . '/' . $format . '?x=' . $width,
            CURLOPT_HTTPHEADER => array(
                'Authorization: ' . $this->token->token_type . ' ' . $this->token->access_token,
                'User-Agent: ' . $_SERVER['HTTP_USER_AGENT']
            ),
            CURLOPT_FOLLOWLOCATION => true
        ));
        curl_exec($ch);
        curl_close($ch);
    }
}
?>
