<?PHP

// Allows the admin to configure other Moodle hosts info

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->libdir.'/adminlib.php');
include_once($CFG->dirroot.'/mnet/lib.php');

require_login();
admin_externalpage_setup('mnetpeers');

$context = get_context_instance(CONTEXT_SYSTEM);

require_capability('moodle/site:config', $context, $USER->id, true, "nopermissions");

if (!extension_loaded('openssl')) {
    admin_externalpage_print_header();
    print_error('requiresopenssl', 'mnet');
}

$site = get_site();

if (!function_exists('curl_init') ) {
    admin_externalpage_print_header();
    print_error('nocurl', 'mnet');
}

/// Initialize variables.

// Step must be one of:
// input   Parse the details of a new host and fetch its public key
// commit  Save our changes (to a new OR existing host)
$step   = optional_param('step', NULL, PARAM_ALPHA);
$hostid = optional_param('hostid', NULL, PARAM_INT);

// Fetch some strings for the HTML templates
$strmnetservices   = get_string('mnetservices', 'mnet');
$strmnetlog        = get_string('mnetlog', 'mnet');
$strmnetedithost   = get_string('reviewhostdetails', 'mnet');
$strmnetthemes     = get_string('mnetthemes', 'mnet');

if (!isset($CFG->mnet_dispatcher_mode)) set_config('mnet_dispatcher_mode', 'off');

/// If data submitted, process and store
if (($form = data_submitted()) && confirm_sesskey()) {

    if (!empty($form->wwwroot)) {
        // ensure we remove trailing slashes
        $form->wwwroot = preg_replace(':/$:', '', $form->wwwroot);

        // ensure the wwwroot starts with a http or https prefix
        if (strtolower(substr($form->wwwroot, 0, 4)) != 'http') {
            $form->wwwroot = 'http://'.$form->wwwroot;
        }
    }

    if(!function_exists('xmlrpc_encode_request')) {
        print_error('xmlrpc-missing', 'mnet','peers.php');
        exit;
    }

    if (!empty($form->updateregisterall)) {
        if (!empty($form->registerallhosts)) {
            set_config('mnet_register_allhosts',1);
        } else {
            set_config('mnet_register_allhosts',0);
        }
        redirect('peers.php', get_string('changessaved'));
    } else {

        $mnet_peer = new mnet_peer();

        if (!empty($form->id)) {
            $form->id = clean_param($form->id, PARAM_INT);
            $mnet_peer->set_id($form->id);
        } else {
            // PARAM_URL requires a genuine TLD (I think) This breaks my testing
            $temp_wwwroot = clean_param($form->wwwroot, PARAM_URL);
            if ($temp_wwwroot !== $form->wwwroot) {
                print_error('invalidurl', 'mnet', 'peers.php');
                exit;
            }
            unset($temp_wwwroot);
            $mnet_peer->set_applicationid($form->applicationid);
            $application = $DB->get_field('mnet_application', 'name', array('id'=>$form->applicationid));
            $mnet_peer->bootstrap($form->wwwroot, null, $application);
        }

        if (isset($form->name) && $form->name != $mnet_peer->name) {
            $form->name = clean_param($form->name, PARAM_NOTAGS);
            $mnet_peer->set_name($form->name);
        }

        if (isset($form->deleted) && ($form->deleted == '0' || $form->deleted == '1')) {
            $mnet_peer->deleted = $form->deleted;
        }

        if (isset($form->public_key)) {
            $form->public_key = clean_param($form->public_key, PARAM_PEM);
            if (empty($form->public_key)) {
                print_error("invalidpubkey", 'mnet', 'peers.php?step=update&amp;hostid='.$mnet_peer->id, '');
                exit;
            } else {
                $oldkey = $mnet_peer->public_key;
                $mnet_peer->public_key = $form->public_key;
                $credentials = $mnet_peer->check_credentials($form->public_key);
                $mnet_peer->public_key_expires = $credentials['validTo_time_t'];
                if ($mnet_peer->public_key_expires == false) {
                    $mnet_peer->public_key == $oldkey;
                    $errmsg = '<br />';
                    foreach ($mnet_peer->error as $err) {
                        $errmsg .= $err['code'] . ': ' . $err['text'].'<br />';
                    }
                    print_error("invalidpubkey", 'mnet', 'peers.php?step=update&amp;hostid='.$mnet_peer->id, $errmsg);
                    exit;
                }
            }
        } else {
            $credentials = $mnet_peer->check_credentials($mnet_peer->public_key);
        }

        // PREVENT DUPLICATE RECORDS ///////////////////////////////////////////
        if ('input' == $form->step) {
            if ( isset($mnet_peer->id) && $mnet_peer->id > 0 ) {
                print_error("hostexists", 'mnet', 'peers.php?step=update&amp;hostid='.$mnet_peer->id, $mnet_peer->id);
            }
        }

        if ('input' == $form->step) {
            include('./mnet_review.html');
        } elseif ('commit' == $form->step) {
            $bool = $mnet_peer->commit();
            if ($bool) {
                redirect('peers.php?step=update&amp;hostid='.$mnet_peer->id, get_string('changessaved'));
            } else {
                print_error('invalidaction', 'error', 'index.php');
            }
        }
    }
} elseif (is_int($hostid)) {
    $mnet_peer = new mnet_peer();
    $mnet_peer->set_id($hostid);
    $currentkey = mnet_get_public_key($mnet_peer->wwwroot, $mnet_peer->application);
    if($currentkey == $mnet_peer->public_key) unset($currentkey);
    $form = new stdClass();
    if ($hostid != $CFG->mnet_all_hosts_id) {
        $credentials = $mnet_peer->check_credentials($mnet_peer->public_key);
        include('./mnet_review.html');
    } else {
        include('./mnet_review_allhosts.html');
    }
} else {
    $hosts = $DB->get_records_sql('  SELECT
                                    h.id,
                                    h.wwwroot,
                                    h.ip_address,
                                    h.name,
                                    h.public_key,
                                    h.public_key_expires,
                                    h.transport,
                                    h.portno,
                                    h.last_connect_time,
                                    h.last_log_id,
                                    h.applicationid,
                                    a.name as app_name,
                                    a.display_name as app_display_name,
                                    a.xmlrpc_server_url
                                FROM
                                    {mnet_host} h,
                                    {mnet_application} a
                                WHERE
                                    h.id <> ? AND
                                    h.deleted = 0 AND
                                    h.applicationid=a.id',
                        array($CFG->mnet_localhost_id));;

    if (empty($hosts)) $hosts = array();
    $applications = $DB->get_records('mnet_application');
    include('./peers.html');
}
