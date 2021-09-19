<?php
// DynDNS client script that calls ISP Config soap to update/create a DNS A Record

// usage:
// First create a Remote User: ISP Config -> System -> Remote Users -> Add new user
// enable Remote Access and two Functions: DNS a functions and DNS zone functions
// https://ddns:password@example.com/dyndns.php?hostname=subdomain&myip=8.12.10.78
// curl -su ddns:password "https://example.com/dyndns.php?hostname=subdomain&myip=8.12.10.78"
// Credit to https://forum.howtoforge.de/threads/dyndns-per-api.12066/
/***** Variables *****/
#The username and password used by the updater to send the request.
#HTTP Basic authentication
$php_auth_user='ddns';
$php_auth_pw='password';

#SOAP config
$soap_location = 'https://localhost:8080/remote/index.php';
$soap_uri = 'https://localhost:8080/remote/';

#ISPConfig server
$ISPCUser = 'ddns';
$ISPCPass = 'password';
$server_id = 1;

#ISPConfig client
$zone_id = 1;
$client_id = 2;

#base domain of which the subdomain has a dynamic ip
$zone = "example.com.";

#subdomain that can not be changed
$exceptions = array('www', 'mail', 'ns', 'ns1', 'ns2', 'ns3');


// Logging

class log
{
    private $file;

    function __construct($name)
    {
        $this->file = str_replace('.', '_', $name) . ".log";
    }

    function debug($log)
    {
        $log = date('Y-m-d H:i:s') . " " . $log . "\n";
        $fid = fopen($this->file, "a+");
        fseek($fid, 0, SEEK_END);
        fputs($fid, $log);
        fclose($fid);
    }

    function clean()
    {
        $fid = fopen($this->file, "a+");
        ftruncate($fid, 0);
        fclose($fid);
    }
}

$log = new log("dyndns");
header('Content-type: text/plain');


if (!isset($_SERVER['PHP_AUTH_USER'])) {
    header('WWW-Authenticate: Basic realm="ISPConfig DynDyns"');
    header('HTTP/1.0 401 Unauthorized');
    die('Authentication Required.');
}

if (!($_SERVER['PHP_AUTH_USER'] == $php_auth_user && $_SERVER['PHP_AUTH_PW'] == $php_auth_pw)) {
    sleep(10);
    die('Invalid Credentials');
}

// Make sure a host was specified
if (empty($_GET['hostname']))
    die('Must specify host');
$host = $_GET["hostname"];

if (in_array($host, $exceptions))
    die('Must specify a valid host');

// Use server value for IP if none was specified
if (empty($_GET['myip'])) {
    $ip = $_SERVER['REMOTE_ADDR'];
} else {
    $ip = $_GET['myip'];
}

// Validate IP address
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
    die('Invalid IP address');

// Get and validate ttl
if (empty($_GET['ttl'])) {
    $ttl = '';
} else {
    $ttl = $_GET['ttl'];
}

if (!is_numeric($ttl) || $ttl < 60)
    $ttl = 300;

$context = stream_context_create(array(
    'ssl' => array(
        'verify_peer' => false,
        'verify_peer_name' => false,
    )
));

$client = new SoapClient(null, array(
    'location' => $soap_location,
    'uri' => $soap_uri,
    'trace' => 1,
    'exceptions' => 1,
    'stream_context' => $context
));

try {
    if ($session_id = $client->login($ISPCUser, $ISPCPass)) {
        //$log->debug('Login OK. Session ID = ' . $session_id);
        $dns_records = $client->dns_rr_get_all_by_zone($session_id, $zone_id);

        $record_id = array_search($host, array_column($dns_records, 'name'));
        if (false !== $record_id) {
            //$log->debug('Record found : ' . print_r($dns_records[$record_id], true));
            if ($dns_records[$record_id]['type'] != 'A') {
                //$log->debug("Not A record!");
                $client->logout($session_id);
                die("Not A record!");
            }
            if ($ip == $dns_records[$record_id]['data']) {
                $log->debug("No update required - USER = " . $php_auth_user . ", HOST = " . $host . ", IP = " . $ip . ", REMOTE_ADDR = " . $_SERVER["REMOTE_ADDR"] . ", TTL = " . $ttl);
                echo "No update required: $host ($ip)\n";
            } else {
                $old_serial = $dns_records[$record_id]['serial'];
                if (substr($old_serial, 0, 8) == date("Ymd")) {
                    $new_serial = $old_serial + 1;
                } else {
                    $new_serial = date("Ymd01");
                }
                $params = array(
                    'server_id' => $server_id,
                    'zone' => $zone_id,
                    'name' => $host,
                    'type' => 'A',
                    'data' => $ip,
                    'aux' => '0',
                    'ttl' => $ttl,
                    'active' => 'y',
                    'stamp' => date("Y-m-d h:i:s"),
                    'serial' => $new_serial
                );

                //$log->debug('Record changed : ' . print_r($dns_records[$record_id], true));
                $affected_rows = $client->dns_a_update($session_id, $client_id, $dns_records[$record_id]['id'], $params);
                //$log->debug("Affected Rows = " . $affected_rows);
                $log->debug("Update successful - USER = " . $php_auth_user . ", HOST = " . $host . ", IP = " . $ip . ", REMOTE_ADDR = " . $_SERVER["REMOTE_ADDR"] . ", TTL = " . $ttl);
                echo "Update successful: $host ($ip)\n";
            }
        } else {
            // Record does not yet exist.
            //$log->debug('Add new record');
            $params = array(
                'server_id' => $server_id,
                'zone' => $zone_id,
                'name' => $host,
                'type' => 'A',
                'data' => $ip,
                'aux' => '0',
                'ttl' => $ttl,
                'active' => 'y',
                'stamp' => date("Y-m-d h:i:s"),
                'serial' => date("Ymd01")
            );
            $record_id = $client->dns_a_add($session_id, $client_id, $params);
            //$log->debug("ID = " . $record_id);
            $log->debug("Registered successfully -  USER = " . $php_auth_user . ", HOST = " . $host . ", IP = " . $ip . ", REMOTE_ADDR = " . $_SERVER["REMOTE_ADDR"] . ", TTL = " . $ttl);
            echo "Registered successfully: $host ($ip)\n";
        }
        if ($client->logout($session_id)) {
            //$log->debug('Logged out.');
        }
    } else {
        echo "denied";
    }
} catch (SoapFault $e) {
    echo $client->__getLastResponse();
    die('SOAP Error: ' . $e->getMessage());
}
