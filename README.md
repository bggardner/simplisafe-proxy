# simplisafe-proxy

A simple PHP class that takes care of authenticating with the SimpliSafe API, allowing simplified access from browsers.  It also includes some common methods so it can used programmatically as a client instead of a proxy.

NOTICE: This project was developed to use with a home automation system, and is meant to be used on a secure local network only.  As your SimpliSafe account credentials are required, be aware of possible security risks.

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

// Get the sesor data (names, states, etc.)
$sensors = $proxy->getSensors();

// Ouput a Flash Video camera stream with width of 1024px
$proxy->streamCamera($proxy->getCameraUuid(0), 'flv', 1024);
// This could also be manipulated to store the stream locally
```

## Playing camera stream in browser
Motion JPEG (`$format='mjpg'`) is supported natively (as the code just reloads a non-Motion JPEG image).  Flash Video (`$format='flv'`) requires [flv.js](https://github.com/bilibili/flv.js).
```javascript
// Motion JPEG
let imgElement = document.getElementById("camera-stream"); // <img> element
fetch("path/to/stream.php").then(response => {
  const reader = response.body.getReader();
  let validData = false;
  let dataBuffer = [];
  const read = () => {
    reader.read().then(({done, value}) => {
      if (done) { return; }
      for (let i = 0; i < value.length; i++) {
        if (value[i] == 0xFF && value[i + 1] == 0xD8) { // Start of image delimeters
          if (validData) {
            imgElement.src = URL.createObjectURL(new Blob([new Uint8Array(dataBuffer)], {type: 'image/jpeg'}));
          }
          dataBuffer = [];
          validData = true;
        }
        if (!validData) { continue; }
        dataBuffer.push(value[i]);
      }
      read();
    }).catch(error => console.log(error));
  }
  read();
}.catch(error => console.log(error));

// Flash Video
let videoElement = document.getElementById("camera-stream"); // <video> element
let flvPlayer = flvjs.createPlayer({
  type: "flv",
  hasAudio: false, // SimpliSafe uses the Speex protocol for audio, which is unsupported by flv.js
  isLive: true,
  url: "path/to/stream.php"
});
flvPlayer.attachMediaElement(videoElement);
flvPlayer.load();
// User needs to click video element play control to play
```

# See also
For sensor type numbers, see [simplisafe-rf](https://github.com/bggardner/simplisafe-rf/blob/master/simplisafe/__init__.py).

A big thanks to the projects below which I used as a guide for developing the code:
* [ssclient](https://github.com/jrassier/ssclient)
* [simplisafe-ss3](https://github.com/rottmanj/simplisafe-ss3)
* [simplisafe-ss3-nodejs](https://github.com/chowielin/simplisafe-ss3-nodejs)
