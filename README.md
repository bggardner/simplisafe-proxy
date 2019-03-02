# simplisafe-proxy

A simple PHP class that takes care of authenticating with the SimpliSafe API, allowing simplified access from browsers.  It also includes some common methods so it can used programmatically as a client instead of a proxy.

This project was developed to use with a home automation system, and is meant to be used on a secure local network only.  As your SimpliSafe account credentials are required, be aware of possible security risks.

# Dependencies
* SimpliSafe monitoring account (required for API access)
* PHP 7

# Usage
```php
require_once('SimpliSafeApiProxy.php');

$proxy = new SimpliSafeApiProxy($username, $password);

// Use as a proxy
header('Content-type: application/json');
exit(json_encode($proxy->get($_GET['url'])));

// Get the alarm state
$alarmState = $proxy->getAlarmState();
// Example result: {"state":"OFF","stateUpdated":1551532144,"exitDelay":0}

// Set the alarm state
$alarmState = $proxy->setAlamrmState(SimpliSafeApiProxy::STATE_AWAY); // or, just 'away'
// Example result: {"state":"AWAY_COUNT","stateUpdated":1551532144,"exitDelay":60}

// Ouput a Flash Video camera stream with width of 1024px
$proxy->streamCamera($proxy->getCameraUuid(0), 'flv', 1024);
// This could also be manipulated to store the stream locally
```

# See also
A big thanks to the projects below which I used as a guide for developing the code:
* [ssclient](https://github.com/jrassier/ssclient)
* [simplisafe-ss3](https://github.com/rottmanj/simplisafe-ss3)
