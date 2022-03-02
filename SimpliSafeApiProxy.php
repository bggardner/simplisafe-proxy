<?php
/**
 * A pseduo-proxy for the SimpliSafe API
 */
class SimpliSafeApiProxy
{
    /** @var string VERSION Version of SimpliSafeApiProxy */
    public const VERSION = '1.0.0';

    /** @var string API_BASE_URL Base URL of the SimpliSafe API */
    public const API_BASE_URL = 'https://api.simplisafe.com/v1';

    /** @var array AUTH_PARAMS POST parameters used during Auth0 authentication */
    public const AUTH_PARAMS = [
        'domain' => 'auth.simplisafe.com',
        'client_id' => '42aBZ5lYrVW12jfOuu3CQROitwxg9sN5',
        'audience' => 'https://api.simplisafe.com/',
        'scope' => 'offline_access openid https://api.simplisafe.com/scopes/user::platform',
        'redirect_uri' => 'com.simplisafe.mobile://auth.simplisafe.com/ios/com.simplisafe.mobile/callback'
    ];

    /** @var string MEDIA_BASE_URL Base URL of the SimpliSafe media server */
    public const MEDIA_BASE_URL = 'https://media.simplisafe.com/v1';

    /** @var array WEBAPP_HEADERS Headers used by SimpliSafe WebApp (probably only need 'Accept') */
    public const WEBAPP_HEADERS = [
        'Origin: https://webapp.simplisafe.com',
        'Referer: https://webapp.simplisafe.com/',
        'Accept: application/json, text/plain, */*',
        'Accept-Encoding: gzip, deflate, br',
        'Accept-Language: en-US,en;q=0.5'
    ];

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
     * @param string $token_path File path of token storage location, including filename
     * @param string $device Name that will be displayed in the 'Manage Logins' section
     */
    function __construct($token_path, $device = __CLASS__)
    {
        $this->token_path = $token_path;
        $this->auth_params = array_merge(self::AUTH_PARAMS, [
            'device' => $device
        ]);
        $this->user = $this->getUser();
        sleep(1);
        $this->subscription = $this->getSubscriptions()[0];
    }

    /**
     * Generates the authorize endpoint URL with query parameters
     *
     * @param string $code_verifier Auth0 code verifier
     *
     * @return string
     */
    protected function getAuthorizeUrl($code_verifier)
    {
        $params = array_merge(
            array_diff_key($this->auth_params, ['domain' => null]), [
                'auth0Client' => base64_encode(json_encode((object) ['name' => __CLASS__, 'version' => static::VERSION])),
                'response_type' => 'code',
                'code_challenge' => preg_replace(['/\+/', '/\//', '/=/'], ['-', '_', ''], base64_encode(hash('sha256', $code_verifier, true))),
                'code_challenge_method' => 'S256'
            ]
        );
        return static::getAuthDomain() . '/authorize?' . http_build_query((object) $params);
    }

    /**
     * Generates the authorization domain including schema
     *
     * @return string
     */
    protected static function getAuthDomain()
    {
        return 'https://' . static::AUTH_PARAMS['domain'];
    }

    /**
     * Gets the access token object from file path or via SimpliSafe API
     *
     * @return object
     */
    public function getToken()
    {
        // Process multi-factor authentication code and request new token
        if ($_POST) {
            $curlopts = [
                CURLOPT_URL => self::getAuthDomain() . '/oauth/token',
                CURLOPT_HTTPHEADER => [
                    'Host: ' . $this->auth_params['domain'],
                    'Content-Type: application/json; charset=utf-8',
                    'User-Agent: ' . $_SERVER['HTTP_USER_AGENT']
                ],
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => json_encode([
                  'grant_type' => 'authorization_code',
                  'client_id' => $this->auth_params['client_id'],
                  'code_verifier' => $_POST['code_verifier'],
                  'code' => $_POST['code'],
                  'redirect_uri' => $this->auth_params['redirect_uri']
                ]),
                CURLOPT_RETURNTRANSFER => true
            ];
            $ch = curl_init();
            curl_setopt_array($ch, $curlopts);
            $response = curl_exec($ch);
            curl_close($ch);
            $this->saveToken($response);
        }

        $this->token = json_decode(file_get_contents($this->token_path));
        if (isset($this->token->access_token)) { // Lazy validity test
            $expires = (new DateTime(
                $this->token->last_refreshed->date,
                new DateTimeZone($this->token->last_refreshed->timezone)
            ))->add(new DateInterval('PT' . $this->token->expires_in . 'S'));
            if ($expires <= new DateTime()) {
                // Expired token, request refresh
                $curlopts = [
                    CURLOPT_URL => self::getAuthDomain() . '/oauth/token',
                    CURLOPT_HTTPHEADER => [
                        'Host: ' . $this->auth_params['domain'],
                        'Content-Type: application/json; charset=utf-8'
                    ],
                    CURLOPT_POST => 1,
                    CURLOPT_POSTFIELDS => json_encode([
                        'grant_type' => 'refresh_token',
                        'client_id' => $this->auth_params['client_id'],
                        'refresh_token' => $this->token->refresh_token
                    ]),
                    CURLOPT_RETURNTRANSFER => true
                ];
                $curlopts[CURLOPT_HTTPHEADER][] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
                $ch = curl_init();
                curl_setopt_array($ch, $curlopts);
                $response = curl_exec($ch);
                curl_close($ch);
                $this->saveToken($response);
            }
            return $this->token;
        }

        // No valid token, perform MFA
        $code_verifier = self::createRandomString();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?= __CLASS__ ?></title>
</head>
<body>
Invalid token. Please follow these steps:
<ol>
  <li><a href="https://webapp.simplisafe.com/new/#/logout" target="_blank">Logout</a> of any existing SimpliSafe WebApp session and close the tab</li>
  <li>Click <a href="<?= $this->getAuthorizeUrl($code_verifier) ?>" target="_blank">here</a> to open a new tab (tab #1) and login with your SimpliSafe credentials</li>
  <li><strong>IMPORTANT:</strong> In tab #1, open the browser's <em>Developer Console</em> (F12) and switch to the <em>Network</em> tab</li>
  <li>Wait for an email from SimpliSafe and click on the <em>Verify Device</em> button/link, which will open a new tab (tab #2)</li>
  <li>Close browser tab #2 and return to tab #1</li>
  <li>In the <em>Network</em> tab of the <em>Developer Console</em>, look for an error regarding <em>auth.simplisafe.com/ios/com.simplisafe.mobile/callback?code=XXX</em></li>
  <li>Copy the code appearing after <em>?code=</em>, paste into the form below, and click <em>Submit</em></li>
  <li>The new device will appear in your account as <em>SimpliSafe iOS: <?= preg_replace('/[;=]/', $this->auth_params['device']) ?></em</li>
</ol>
<form method="post">
<input type="hidden" name="code_verifier" value="<?= $code_verifier ?>"><br>
<label for="code">Code:</label> <input for="code" name="code">
<button type="submit">Submit</button>
</form>
</body>
</html>
<?php
        exit;
    }

    /**
      * Creates a random 43-character string from a limited character set
      *
      * @return string
      */
    protected static function createRandomString() : string
    {
        $charset = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-_~.';
        $max = strlen($charset) - 1;
        $random = '';
        for ($i = 0; $i < 43; $i++) {
            $random .= substr($charset, rand(0, $max), 1);
        }
        return $random;
    }

    /**
      * Parses and saves the latest token information
      *
      * @param string $response Response from an authorization request
      *
      */
    protected function saveToken($response)
    {
        $token = json_decode($response);
        if (isset($token->error)) {
            throw new Exception($token->error . ': ' . $token->error_description);
        }
        $token->last_refreshed = new DateTime('now', new DateTimeZone('UTC'));
        file_put_contents($this->token_path, json_encode($token));
        $this->token = $token;
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
        $token = $this->getToken();
        $curlopts = array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => SELF::WEBAPP_HEADERS,
            CURLOPT_RETURNTRANSFER => true
        );
        $curlopts[CURLOPT_HTTPHEADER][] = 'Host: api.simplisafe.com';
        $curlopts[CURLOPT_HTTPHEADER][] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
        $curlopts[CURLOPT_HTTPHEADER][] = 'Authorization: ' . $token->token_type . ' ' . $token->access_token;
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
        $token = $this->getToken();
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
        $curlopts[CURLOPT_HTTPHEADER][] = 'Authorization: ' . $token->token_type . ' ' . $token->access_token;
        $ch = curl_init();
        curl_setopt_array($ch, $curlopts);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response);
    }

    /**
     * Gets alarm state JSON object via the SimipliSafe API
     *
     * @return object
     */
    public function getAlarmState()
    {
        $version = $this->subscription->location->system->version;
        switch ($version) {
            case 3:
                $url = self::API_BASE_URL . '/ss3/subscriptions/' . $this->subscription->sid . '/state';
                break;
            default:
                throw new Exception('getAlarmState() for version ' . $version . ' not implemented');
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
        if (!isset($this->user->userId)) {
            throw new Exception('User ID not set!');
        }
        return $this->get(self::API_BASE_URL . '/users/' . $this->user->userId . '/subscriptions?activeOnly=true')->subscriptions;
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
