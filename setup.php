<?php
 /*
 *
 * bouncehandler.php | MailWizz / PowerMTA / Webhook bounce handler
 * Copyright (c) 2016-2017 Gerd Naschenweng / bidorbuy.co.za
 *
 * The MIT License (MIT)
 *
 * @author Gerd Naschenweng <gerd@naschenweng.info>
 * @link https://www.naschenweng.info/
 * @copyright 2016-2017 Gerd Naschenweng  https://github.com/magicdude4eva
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 */

// Logging configuration. 1=log to console / 0=log to file
$LOG_CONSOLE_MODE = 0;
$LOG_FILE         = "/var/log/pmta/pmta-bounce-handler.log";

// Statistics handling
$LOG_STATS_FILE_ONLY = true; // set to true if you want to skip email notifications
$LOG_STATS_FILE      = "/var/log/pmta/pmta-bounce-stats.log";


// Handle the following bounce-categories only
// Leave empty to handle all bounce-categories
$bounceCategories = array("bad-mailbox","bad-domain","routing-errors","inactive-mailbox", "bad-configuration", "bad-connection", "content-related", "invalid-sender", "other", "policy-related", "quota-issues", "spam-related", "virus-related");

// Specify soft-bounce-categories. This is used to classify bounces in MailWizz as a soft-bounce. If not in the list or if the
// list is empty, all bounces are considered hard-bounces
$softbounceCategories = array("bad-configuration", "bad-connection", "content-related", "invalid-sender", "other", "policy-related", "quota-issues", "spam-related", "virus-related");

// ------------------------------------------------------------------------------------------------------
// RRD Graphs - requires installation of php-rrdtool - if not defined, it will not be enabled
// define("RRD_FILE",        "/var/log/pmta/pmta.rrd");

// ------------------------------------------------------------------------------------------------------
// INTERSPIRE BOUNCE CONFIGURATION - leave empty/undefined if not needed
//define("INTERSPIRE_API_KEY",        "MY_API_KEY");
//define("INTERSPIRE_ENDPOINT_URL",   "http://interspire.domain.com/xml.php");
//define("INTERSPIRE_USER_ID",        "admin");

// Define which from-addresses should be handled for the bounces
$origInterspire = array("campaigns@interspire.domain.com");

// ------------------------------------------------------------------------------------------------------
// MAILWIZZ BOUNCE CONFIGURATION - leave empty/undefined if not needed
define("MAILWIZZ_API_PUBLIC_KEY",   "MY_PUBLIC_KEY");
define("MAILWIZZ_API_PRIVATE_KEY",  "MY_PRIVATE_KEY");
define("MAILWIZZ_ENDPOINT_URL",     "https://mailer.vpsfix.com/api");

// Define which from-addresses should be handled for the bounces
$origMailWizzZA = array("campaign@mailwizz.com");

// ------------------------------------------------------------------------------------------------------
// TRANSACTIONAL BOUNCE CONFIGURATION - leave empty/undefined if not needed
// Define which from-addresses should be handled for the bounces
$origTransactional = array("");



// Timeout for webhook curl calls
define("ENDPOINT_TIMEOUT",          30);

// ------------------------------------------------------------------------------------------------------
// You should not have to touch anything below
// Use UTC as default
date_default_timezone_set('UTC');

// Initialise options via command-line
$options = getopt("dl::", array("debug", "logfile::"));
if (!empty($options)) {
  foreach (array_keys($options) as $option) {
    switch ($option) {
      case 'd':
      case 'debug':
        $LOG_CONSOLE_MODE=1;
        break;
      case 'l':
      case 'logfile':
        $LOG_FILE=$options[$option];
        break;
    }
  }
}

// Logging class initialization
$log = new Logging();
$log->ldebug($LOG_CONSOLE_MODE);
$log->lfile($LOG_FILE);

// Stats file initialisation
$statsfile = new Logging();
$statsfile->lfile($LOG_STATS_FILE);


// ========================================================================================================
// LOGGING CLASS
class Logging {
    // declare log file and file pointer as private properties
    private $log_file, $fp, $debug = 0;

    // set log file (path and name)
    public function lfile($path) {
        $this->log_file = $path;
    }
    public function ldebug($debugOption) {
        $this->debug = $debugOption;
    }

    // write message to the log file
    public function lwrite($message) {
        // if file pointer doesn't exist, then open log file
        if ($this->debug == 0 && !is_resource($this->fp)) {
            $this->lopen();
        }
        // define current time and suppress E_WARNING if using the system TZ settings
        // (don't forget to set the INI setting date.timezone)
        $time = @date('[d/M/Y H:i:s]');
        // write current time, script name and message to the log file
        if ($this->debug == 1) {
        	echo "$time $message" . PHP_EOL;
        } else {
        	fwrite($this->fp, "$time $message" . PHP_EOL);
        }
    }
    // close log file (it's always a good idea to close a file when you're done with it)
    public function lclose() {
        if ($this->debug == 0 && is_resource($this->fp)) {
          fclose($this->fp);
        }
    }
    // open log file (private method)
    private function lopen() {
        $log_file_default = '/var/log/pmta/pmta-bounce-handler.log';
        // define log file from lfile method or use previously set default
        $lfile = $this->log_file ? $this->log_file : $log_file_default;
        // open log file for writing only and place file pointer at the end of the file
        // (if the file does not exist, try to create it)
        // First try to write to configured log-file
        if ($this->debug == 0) {
	        $this->fp = fopen($lfile, 'a') or $lfile = 'pmta-bounce-handler.log';
	    }

        if ($this->debug == 0 && is_null($this->fp)) {
	        $this->fp = fopen($lfile, 'a') or exit("Can't open $lfile!");
	    }
    }
}
// LOGGING CLASS
// ========================================================================================================

// ========================================================================================================
// Bounce Reporting class
class BounceReporting {
  // declare log file and file pointer as private properties
  private $rrdFile = null, $rrdUpdater = null;

  function __construct($aRRDFile) {
    global $log;
    $log->lwrite('Initialising RRD reporting via ' . $aRRDFile);

    if (!extension_loaded('rrd') && !dl('rrd.so')) {
      $log->lwrite('  RRD not installed, please install php-pecl-rrd or php-rrdtool');
      return;
    }

    if (!file_exists($aRRDFile)) {
      try {
        $log->lwrite('  Creating new RRD file...');
        $creator = new RRDCreator($aRRDFile, "now", 300);
        $creator->addDataSource("fbl_reports:ABSOLUTE:600:0:U");
        $creator->addDataSource("bounces:ABSOLUTE:600:0:U");
        $creator->addDataSource("bounce_mailwizz:ABSOLUTE:600:0:U");
        $creator->addDataSource("bounce_interspire:ABSOLUTE:600:0:U");
        $creator->addDataSource("bounce_bidorbuy:ABSOLUTE:600:0:U");
        $creator->addArchive("AVERAGE:0.5:1:288");
        $creator->addArchive("AVERAGE:0.5:12:168");
        $creator->addArchive("AVERAGE:0.5:228:365");
        $creator->save();
        $this->rrdFile = $aRRDFile;
        $log->lwrite('  RRD file initialised!');
      } catch (Exception $ex) {
        $log->lwrite('  Failed creating RRD with error=' . $ex->getMessage() . "! Please check path and permissions!");
      }
    } else {
        $this->rrdFile = $aRRDFile;
    }
  }

  function logReportRecord($recordingFields, $recordingCounter = 1) {
    global $log;

    if (is_null($recordingFields) || empty($recordingFields) || is_null($this->rrdFile) || empty($this->rrdFile) || !file_exists($this->rrdFile)) {
      return;
    }

    try {
      if (is_null($this->rrdUpdater)) {
        $this->rrdUpdater = new RRDUpdater($this->rrdFile);
      }

      $tempRecordFields = explode(',', $recordingFields);
      foreach ($tempRecordFields as $rrdRecord) {
        $this->rrdUpdater->update(array($rrdRecord => (null === $recordingCounter ? 1 : $recordingCounter)));
      }
    } catch (Exception $ex) {
        $log->lwrite('  Failed writing RRD-record "' . $recordingFields . '", error=' . $ex->getMessage());
    }
  }
}

// ========================================================================================================
// BounceUtility
class BounceUtility {
// Test if URL is available
public static function testEndpointURL($endpointURL) {
  global $log;
  $ch = curl_init($endpointURL);
  curl_setopt($ch,  CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  $result = curl_exec($ch);

  if ($result === false || is_null($result) || empty($result)) {
    $log->lwrite('   Failed connecting to ' . $endpointURL . ', check conncetivity!');
    return false;
  }
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

  if ($httpCode != 200 && $httpCode != 301 && $httpCode != 302) {
    $log->lwrite('   Failed connecting to ' . $endpointURL . ', error=' . $httpCode);
    return false;
  }

  return true;
}
}
