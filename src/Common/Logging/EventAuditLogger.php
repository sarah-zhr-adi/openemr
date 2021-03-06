<?php
/**
 * Class to log audited events - must be high performance
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Kyle Wiering <kyle@softwareadvice.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Kyle Wiering <kyle@softwareadvice.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Common\Logging;

use \DateTime;
use OpenEMR\Common\Crypto\CryptoGen;
use Waryway\PhpTraitsLibrary\Singleton;

class EventAuditLogger
{
    use Singleton;

    /**
     * Event action codes indicate whether the event is read/write.
     * C = create, R = read, U = update, D = delete, E = execute
     */
    private const EVENT_ACTION_CODE_EXECUTE = 'E';
    private const EVENT_ACTION_CODE_CREATE = 'C';
    private const EVENT_ACTION_CODE_INSERT = 'C';
    private const EVENT_ACTION_CODE_SELECT = 'R';
    private const EVENT_ACTION_CODE_UPDATE = 'U';
    private const EVENT_ACTION_CODE_DELETE = 'D';

    /**
     * Keep track of the table mapping in a class constant to prevent reloading the data each time the method is called.
     *
     * @var array
     */
    private const LOG_TABLES = [
        "billing" => "patient-record",
        "claims" => "patient-record",
        "employer_data" => "patient-record",
        "forms" => "patient-record",
        "form_encounter" => "patient-record",
        "form_dictation" => "patient-record",
        "form_misc_billing_options" => "patient-record",
        "form_reviewofs" => "patient-record",
        "form_ros" => "patient-record",
        "form_soap" => "patient-record",
        "form_vitals" => "patient-record",
        "history_data" => "patient-record",
        "immunizations" => "patient-record",
        "insurance_data" => "patient-record",
        "issue_encounter" => "patient-record",
        "lists" => "patient-record",
        "patient_data" => "patient-record",
        "payments" => "patient-record",
        "pnotes" => "patient-record",
        "onotes" => "patient-record",
        "prescriptions" => "order",
        "transactions" => "patient-record",
        "amendments" => "patient-record",
        "amendments_history" => "patient-record",
        "facility" => "security-administration",
        "pharmacies" => "security-administration",
        "addresses" => "security-administration",
        "phone_numbers" => "security-administration",
        "x12_partners" => "security-administration",
        "insurance_companies" => "security-administration",
        "codes" => "security-administration",
        "registry" => "security-administration",
        "users" => "security-administration",
        "groups" => "security-administration",
        "openemr_postcalendar_events" => "scheduling",
        "openemr_postcalendar_categories" => "security-administration",
        "openemr_postcalendar_limits" => "security-administration",
        "openemr_postcalendar_topics" => "security-administration",
        "gacl_acl" => "security-administration",
        "gacl_acl_sections" => "security-administration",
        "gacl_acl_seq" => "security-administration",
        "gacl_aco" => "security-administration",
        "gacl_aco_map" => "security-administration",
        "gacl_aco_sections" => "security-administration",
        "gacl_aco_sections_seq" => "security-administration",
        "gacl_aco_seq" => "security-administration",
        "gacl_aro" => "security-administration",
        "gacl_aro_groups" => "security-administration",
        "gacl_aro_groups_id_seq" => "security-administration",
        "gacl_aro_groups_map" => "security-administration",
        "gacl_aro_map" => "security-administration",
        "gacl_aro_sections" => "security-administration",
        "gacl_aro_sections_seq" => "security-administration",
        "gacl_aro_seq" => "security-administration",
        "gacl_axo" => "security-administration",
        "gacl_axo_groups" => "security-administration",
        "gacl_axo_groups_map" => "security-administration",
        "gacl_axo_map" => "security-administration",
        "gacl_axo_sections" => "security-administration",
        "gacl_groups_aro_map" => "security-administration",
        "gacl_groups_axo_map" => "security-administration",
        "gacl_phpgacl" => "security-administration",
        "procedure_order" => "lab-order",
        "procedure_order_code" => "lab-order",
        "procedure_report" => "lab-results",
        "procedure_result" => "lab-results"
    ];

    private const RFC3881_MSG_PRIMARY_TEMPLATE = <<<MSG
<13>%s %s
<?xml version="1.0" encoding="ASCII"?>
 <AuditMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="healthcare-security-audit.xsd">
  <EventIdentification EventActionCode="%s" EventDateTime="%s" EventOutcomeIndicator="%s">
   <EventID code="eventIDcode" displayName="%s" codeSystemName="DCM" />
  </EventIdentification>
  <ActiveParticipant UserID="%s" UserIsRequestor="true" NetworkAccessPointID="%s" NetworkAccessPointTypeCode="2" >
   <RoleIDCode code="110153" displayName="Source" codeSystemName="DCM" />
  </ActiveParticipant>
  <ActiveParticipant UserID="%s" UserIsRequestor="false" NetworkAccessPointID="%s" NetworkAccessPointTypeCode="2" >
   <RoleIDCode code="110152" displayName="Destination" codeSystemName="DCM" />
  </ActiveParticipant>
  <AuditSourceIdentification AuditSourceID="%s" />
  <ParticipantObjectIdentification ParticipantObjectID="%s" ParticipantObjectTypeCode="1" ParticipantObjectTypeCodeRole="6" >
   <ParticipantObjectIDTypeCode code="11" displayName="User Identifier" codeSystemName="RFC-3881" />
  </ParticipantObjectIdentification>
  %s
 </AuditMessage>
MSG;

    private const RFC3881_MSG_PATIENT_TEMPLATE = <<<MSG
<ParticipantObjectIdentification ParticipantObjectID="%s" ParticipantObjectTypeCode="1" ParticipantObjectTypeCodeRole="1">
 <ParticipantObjectIDTypeCode code="2" displayName="Patient Number" codeSystemName="RFC-3881" />
</ParticipantObjectIdentification>
MSG;

    /**
     * @param $event
     * @param $user
     * @param $groupname
     * @param $success
     * @param string    $comments
     * @param null      $patient_id
     * @param string    $log_from
     * @param string    $menu_item
     * @param int       $ccda_doc_id
     */
    public function newEvent($event, $user, $groupname, $success, $comments = "", $patient_id = null, $log_from = 'open-emr', $menu_item = 'dashboard', $ccda_doc_id = 0)
    {
        // Set up crypto object that will be used by this singleton class for for encryption/decryption (if not set up already)
        if (!isset($this->cryptoGen)) {
            $this->cryptoGen = new CryptoGen();
        }

        $adodb = $GLOBALS['adodb']['db'];
        $crt_user=isset($_SERVER['SSL_CLIENT_S_DN_CN']) ?  $_SERVER['SSL_CLIENT_S_DN_CN'] : null;

        $category = $event;
        // Special case delete for lists table
        if ($event == 'delete') {
            $category = $this->eventCategoryFinder($comments, $event, '');
        }

        // deal with comments encryption, if turned on
        $encrypt_comment = 'No';
        if (!empty($comments)) {
            if ($GLOBALS["enable_auditlog_encryption"]) {
                $comments =  $this->cryptoGen->encryptStandard($comments);
                $encrypt_comment = 'Yes';
            }
        }

        if ($log_from == 'patient-portal') {
            $sqlMenuItems = "SELECT * FROM patient_portal_menu";

            $resMenuItems = sqlStatement($sqlMenuItems);
            for ($iter=0; $rowMenuItem=sqlFetchArray($resMenuItems); $iter++) {
                $menuItems[$rowMenuItem['patient_portal_menu_id']] = $rowMenuItem['menu_name'];
            }

            $menuItemId = array_search($menu_item, $menuItems);
            $sql = "insert into log ( date, event,category, user, patient_id, groupname, success, comments,
                log_from, menu_item_id, crt_user, ccda_doc_id) values ( NOW(), ?,'Patient Portal', ?, ?, ?, ?, ?, ?, ?,?, ?)";
            $ret = sqlStatementNoLog($sql, array($event, $user, $patient_id, $groupname, $success, $comments,$log_from, $menuItemId,$crt_user, $ccda_doc_id));
        } else {
            /* More details added to the log */
            $sql = "insert into log ( date, event,category, user, groupname, success, comments, crt_user, patient_id) " .
                "values ( NOW(), " . $adodb->qstr($event) . ",". $adodb->qstr($category) . "," . $adodb->qstr($user) .
                "," . $adodb->qstr($groupname) . "," . $adodb->qstr($success) . "," .
                $adodb->qstr($comments) ."," .
                $adodb->qstr($crt_user) ."," . $adodb->qstr($patient_id). ")";

            $ret = sqlInsertClean_audit($sql);
        }

        // Send item to log_comment_encrypt for comment encyption tracking
        $last_log_id = $GLOBALS['adodb']['db']->Insert_ID();
        $encryptLogQry = "INSERT INTO log_comment_encrypt (log_id, encrypt, checksum, `version`) ".
            " VALUES ( ".
            $adodb->qstr($last_log_id) . "," .
            $adodb->qstr($encrypt_comment) . "," .
            "'', '3')";
        sqlInsertClean_audit($encryptLogQry);

        if (($patient_id=="NULL") || ($patient_id==null)) {
            $patient_id=0;
        }

        $this->sendAtnaAuditMsg($user, $groupname, $event, $patient_id, $success, $comments);
    }

    /******************
     * Get records from the LOG and Extended_Log table
     * using the optional parameters:
     *   date : a specific date  (defaults to today)
     *   user : a specific user  (defaults to none)
     *   cols : gather specific columns  (defaults to date,event,user,groupname,comments)
     *   sortby : sort the results by  (defaults to none)
     * RETURNS:
     *   array of results
     ******************/
    public function getEvents($params)
    {
        // parse the parameters
        $cols = "DISTINCT date, event, category, user, groupname, patient_id, success, comments,checksum,crt_user, id ";
        if (isset($params['cols']) && $params['cols'] != "") {
            $cols = $params['cols'];
        }

        $date1 = date("Y-m-d H:i:s", time());
        if (isset($params['sdate']) && $params['sdate'] != "") {
            $date1= $params['sdate'];
        }

        $date2 = date("Y-m-d H:i:s", time());
        if (isset($params['edate']) && $params['edate'] != "") {
            $date2= $params['edate'];
        }

        $user = "";
        if (isset($params['user']) && $params['user'] != "") {
            $user= $params['user'];
        }

        //VicarePlus :: For Generating log with patient id.
        $patient = "";
        if (isset($params['patient']) && $params['patient'] != "") {
            $patient= $params['patient'];
        }

        $sortby = "";
        if (isset($params['sortby']) && $params['sortby'] != "") {
            $sortby = $params['sortby'];
        }

        $levent = "";
        if (isset($params['levent']) && $params['levent'] != "") {
            $levent = $params['levent'];
        }

        $tevent = "";
        if (isset($params['tevent']) && $params['tevent'] != "") {
            $tevent = $params['tevent'];
        }

        $direction = 'asc';
        if (isset($params['direction']) && $params['direction'] != "") {
            $direction = $params['direction'];
        }

        $event = "";
        if (isset($params['event']) && $params['event'] != "") {
            $event = $params['event'];
        }

        if ($event!="") {
            if ($sortby == "comments") {
                $sortby = "description";
            }

            if ($sortby == "groupname") {
                $sortby = ""; //VicarePlus :: since there is no groupname in extended_log
            }

            if ($sortby == "success") {
                $sortby = "";   //VicarePlus :: since there is no success field in extended_log
            }

            if ($sortby == "checksum") {
                $sortby = "";  //VicarePlus :: since there is no checksum field in extended_log
            }

            if ($sortby == "category") {
                $sortby = "";  //VicarePlus :: since there is no category field in extended_log
            }

            $sqlBindArray = array();
            $columns = "DISTINCT date, event, user, recipient,patient_id,description";
            $sql = "SELECT $columns FROM extended_log WHERE date >= ? AND date <= ?";
            array_push($sqlBindArray, $date1, $date2);

            if ($user != "") {
                $sql .= " AND user LIKE ?";
                array_push($sqlBindArray, $user);
            }

            if ($patient != "") {
                $sql .= " AND patient_id LIKE ?";
                array_push($sqlBindArray, $patient);
            }

            if ($levent != "") {
                $sql .= " AND event LIKE ?";
                array_push($sqlBindArray, $levent . "%");
            }

            if ($sortby != "") {
                $sql .= " ORDER BY " . escape_sql_column_name($sortby, array('extended_log')) . " DESC"; // descending order
            }

            $sql .= " LIMIT 5000";
        } else {
            // do the query
            $sqlBindArray = array();
            $sql = "SELECT $cols FROM log WHERE date >= ? AND date <= ?";
            array_push($sqlBindArray, $date1, $date2);

            if ($user != "") {
                $sql .= " AND user LIKE ?";
                array_push($sqlBindArray, $user);
            }

            if ($patient != "") {
                $sql .= " AND patient_id LIKE ?";
                array_push($sqlBindArray, $patient);
            }

            if ($levent != "") {
                $sql .= " AND event LIKE ?";
                array_push($sqlBindArray, $levent . "%");
            }

            if ($tevent != "") {
                $sql .= " AND event LIKE ?";
                array_push($sqlBindArray, "%" . $tevent);
            }

            if ($sortby != "") {
                $sql .= " ORDER BY " . escape_sql_column_name($sortby, array('log')) . "  ".escape_sort_order($direction); // descending order
            }

            $sql .= " LIMIT 5000";
        }

        $res = sqlStatement($sql, $sqlBindArray);
        for ($iter=0; $row=sqlFetchArray($res); $iter++) {
            $all[$iter] = $row;
        }

        return $all;
    }

    /*
     *
     */

    /**
     * Given an SQL insert/update that was just performeds:
     * - Find the table and primary id of the row that was created/modified
     * - Calculate the SHA1 checksum of that row (with all the
     *   column values concatenated together).
     * - Return the SHA1 checksum as a 40 char hex string.
     * If this is not an insert/update query, return "".
     * If multiple rows were modified, return "".
     * If we're unable to determine the row modified, return "".
     *
     * @todo   May need to incorporate the binded stuff (still analyzing)
     * @param  $statement
     * @return string
     */
    private function sqlChecksumOfModifiedRow($statement)
    {
        $table = "";
        $rid = "";

        $tokens = preg_split("/[\s,(\'\"]+/", $statement);
        /* Identifying the id for insert/replace statements for calculating the checksum */
        if ((strcasecmp($tokens[0], "INSERT")==0) || (strcasecmp($tokens[0], "REPLACE")==0)) {
            $table = $tokens[2];
            $rid = generic_sql_insert_id();
            /* For handling the table that doesn't have auto-increment column */
            if ($rid === 0 || $rid === false) {
                if ($table == "gacl_aco_map" || $table == "gacl_aro_groups_map" || $table == "gacl_aro_map" || $table == "gacl_axo_groups_map" || $table == "gacl_axo_map") {
                    $id="acl_id";
                } else if ($table == "gacl_groups_aro_map" || $table == "gacl_groups_axo_map") {
                    $id="group_id";
                } else {
                    $id="id";
                }

                /* To handle insert statements */
                if ($tokens[3] == $id) {
                    for ($i=4; $i<count($tokens); $i++) {
                        if (strcasecmp($tokens[$i], "VALUES")==0) {
                            $rid=$tokens[$i+1];
                            break;
                        } // if close
                    } //for close
                } else if (strcasecmp($tokens[3], "SET")==0) { //if close
                    /* To handle replace statements */
                    if ((strcasecmp($tokens[4], "ID")==0) || (strcasecmp($tokens[4], "`ID`")==0)) {
                        $rid=$tokens[6];
                    } // if close
                } else {
                    return "";
                }
            }
        } else if (strcasecmp($tokens[0], "UPDATE")==0) { // Identifying the id for update statements for
            // calculating the checksum.
            $table = $tokens[1];

            $offset = 3;
            $total = count($tokens);

            /* Identifying the primary key column for the updated record */
            if ($table == "form_physical_exam") {
                $id = "forms_id";
            } else if ($table == "claims") {
                $id = "patient_id";
            } else if ($table == "openemr_postcalendar_events") {
                $id = "pc_eid";
            } else if ($table == "lang_languages") {
                $id = "lang_id";
            } else if ($table == "openemr_postcalendar_categories" || $table == "openemr_postcalendar_topics") {
                $id = "pc_catid";
            } else if ($table == "openemr_postcalendar_limits") {
                $id = "pc_limitid";
            } else if ($table == "gacl_aco_map" || $table == "gacl_aro_groups_map" || $table == "gacl_aro_map" || $table == "gacl_axo_groups_map" || $table == "gacl_axo_map") {
                $id="acl_id";
            } else if ($table == "gacl_groups_aro_map" || $table == "gacl_groups_axo_map") {
                $id="group_id";
            } else {
                $id = "id";
            }

            /* Identifying the primary key value for the updated record */
            while ($offset < $total) {
                /* There are 4 possible ways that the id=123 can be parsed:
                * ('id', '=', '123')
                * ('id=', '123')
                * ('id=123')
                * ('id', '=123')
                */
                $rid = "";
                /*id=', '123'*/
                if (($tokens[$offset] == "$id=") && ($offset + 1 < $total)) {
                    $rid = $tokens[$offset+1];
                    break;
                } else if ($tokens[$offset] == "$id" && $tokens[$offset+1] == "=" && ($offset+2 < $total)) {
                    /* 'id', '=', '123' */
                    $rid = $tokens[$offset+2];
                    break;
                } else if (strpos($tokens[$offset], "$id=") === 0) { /*id=123*/
                    $tid = substr($tokens[$offset], strlen($id)+1);
                    if (is_numeric($tid)) {
                        $rid=$tid;
                    }

                    break;
                } else if ($tokens[$offset] == "$id") { /*'id', '=123' */
                    $tid = substr($tokens[$offset+1], 1);
                    if (is_numeric($tid)) {
                        $rid=$tid;
                    }

                    break;
                }

                $offset += 1;
            } // while ($offset < $total)
        } // else if ($tokens[0] == 'update' || $tokens[0] == 'UPDATE' )

        if ($table == "" || $rid == "") {
            return "";
        }

        /* Framing sql statements for calculating checksum */
        if ($table == "form_physical_exam") {
            $sql = "select * from $table where forms_id = ?";
        } else if ($table == "claims") {
            $sql = "select * from $table where patient_id = ?";
        } else if ($table == "openemr_postcalendar_events") {
            $sql = "select * from $table where pc_eid = ?";
        } else if ($table == "lang_languages") {
            $sql = "select * from $table where lang_id = ?";
        } else if ($table == "openemr_postcalendar_categories" || $table == "openemr_postcalendar_topics") {
            $sql = "select * from $table where pc_catid = ?";
        } else if ($table == "openemr_postcalendar_limits") {
            $sql = "select * from $table where pc_limitid = ?";
        } else if ($table ==  "gacl_aco_map" || $table == "gacl_aro_groups_map" || $table == "gacl_aro_map" || $table == "gacl_axo_groups_map" || $table == "gacl_axo_map") {
            $sql = "select * from $table where acl_id = ?";
        } else if ($table == "gacl_groups_aro_map" || $table == "gacl_groups_axo_map") {
            $sql = "select * from $table where group_id = ?";
        } else {
            $sql = "select * from $table where id = ?";
        }

        // When this function is working perfectly, can then shift to the
        // sqlQueryNoLog() function.
        $results = sqlQueryNoLogIgnoreError($sql, [$rid]);
        $column_values = "";
        /* Concatenating the column values for the row inserted/updated */
        if (is_array($results)) {
            foreach ($results as $field_name => $field) {
                $column_values .= $field;
            }
        }

        // ViCarePlus: As per NIST standard, the encryption algorithm SHA1 is used

        //error_log("COLUMN_VALUES: ".$column_values,0);
        return sha1($column_values);
    }

    /**
     * Event action codes indicate whether the event is read/write.
     * C = create, R = read, U = update, D = delete, E = execute
     *
     * @param  $event
     * @return string
     */
    private function determineRFC3881EventActionCode($event)
    {
        switch (substr($event, -7)) {
            case '-create':
                return self::EVENT_ACTION_CODE_CREATE;
                break;
            case '-insert':
                return self::EVENT_ACTION_CODE_INSERT;
                break;
            case '-select':
                return self::EVENT_ACTION_CODE_SELECT;
                break;
            case '-update':
                return self::EVENT_ACTION_CODE_UPDATE;
                break;
            case '-delete':
                return self::EVENT_ACTION_CODE_DELETE;
                break;
            default:
                return self::EVENT_ACTION_CODE_EXECUTE;
                break;
        }
    }

    /**
     * The choice of event codes is up to OpenEMR.
     * We're using the same event codes as
     * https://iheprofiles.projects.openhealthtools.org/
     *
     * @param $event
     */
    private function determineRFC3881EventIdDisplayName($event)
    {

        $eventIdDisplayName = $event;

        if (strpos($event, 'patient-record') !== false) {
            $eventIdDisplayName = 'Patient Record';
        } else if (strpos($event, 'view') !== false) {
            $eventIdDisplayName = 'Patient Record';
        } else if (strpos($event, 'login') !== false) {
            $eventIdDisplayName = 'Login';
        } else if (strpos($event, 'logout') !== false) {
            $eventIdDisplayName = 'Logout';
        } else if (strpos($event, 'scheduling') !== false) {
            $eventIdDisplayName = 'Patient Care Assignment';
        } else if (strpos($event, 'security-administration') !== false) {
            $eventIdDisplayName = 'Security Administration';
        }

        return $eventIdDisplayName;
    }

    /**
     * Create an XML audit record corresponding to RFC 3881.
     * The parameters passed are the column values (from table 'log')
     * for a single audit record.
     *
     * @param  $user
     * @param  $group
     * @param  $event
     * @param  $patient_id
     * @param  $outcome
     * @param  $comments
     * @return string
     */
    private function createRfc3881Msg($user, $group, $event, $patient_id, $outcome, $comments)
    {
        $eventActionCode = $this->determineRFC3881EventActionCode($event);
        $eventIdDisplayName = $this->determineRFC3881EventIdDisplayName($event);

        $eventDateTime = (new DateTime())->format(DATE_ATOM);

        /* For EventOutcomeIndicator, 0 = success and 4 = minor error */
        $eventOutcome = ($outcome === 1) ? 0 : 4;

        /*
         * Variables used in ActiveParticipant section, which identifies
         * the IP address and application of the source and destination.
         */
        $srcUserID = $_SERVER['SERVER_NAME'] . '|OpenEMR';
        $srcNetwork = $_SERVER['SERVER_ADDR'];
        $destUserID = $GLOBALS['atna_audit_host'];
        $destNetwork = $GLOBALS['atna_audit_host'];

        $patientRecordForMsg = ($eventIdDisplayName == 'Patient Record' && $patient_id != 0) ? sprintf(self::RFC3881_MSG_PATIENT_TEMPLATE, $patient_id) : '';
        /* Add the syslog header  with $eventDateTime and $_SERVER['SERVER_NAME'] */
        return sprintf(self::RFC3881_MSG_PRIMARY_TEMPLATE, $eventDateTime, $_SERVER['SERVER_NAME'], $eventActionCode, $eventDateTime, $eventOutcome, $eventIdDisplayName, $srcUserID, $srcNetwork, $destUserID, $destNetwork, $srcUserID, $user, $patientRecordForMsg);
    }

    /**
     * Create a TLS (SSLv3) connection to the given host/port.
     * $localcert is the path to a PEM file with a client certificate and private key.
     * $cafile is the path to the CA certificate file, for
     *  authenticating the remote machine's certificate.
     * If $cafile is "", the remote machine's certificate is not verified.
     * If $localcert is "", we don't pass a client certificate in the connection.
     *
     * Return a stream resource that can be used with fwrite(), fread(), etc.
     * Returns FALSE on error.
     *
     * @param  $host
     * @param  $port
     * @param  $localcert
     * @param  $cafile
     * @return bool|resource
     */
    private function createTlsConn($host, $port, $localcert, $cafile)
    {
        $sslopts = array();
        if ($cafile !== null && $cafile != "") {
            $sslopts['cafile'] = $cafile;
            $sslopts['verify_peer'] = true;
            $sslopts['verify_depth'] = 10;
        }

        if ($localcert !== null && $localcert != "") {
            $sslopts['local_cert'] = $localcert;
        }

        $opts = array('tls' => $sslopts, 'ssl' => $sslopts);
        $ctx = stream_context_create($opts);
        $timeout = 60;
        $flags = STREAM_CLIENT_CONNECT;

        $olderr = error_reporting(0);
        $conn = stream_socket_client(
            'tls://' . $host . ":" . $port,
            $errno,
            $errstr,
            $timeout,
            $flags,
            $ctx
        );
        error_reporting($olderr);
        return $conn;
    }

    /**
     * This function is used to send audit records to an Audit Repository Server,
     * as described in the Audit Trail and Node Authentication (ATNA) standard.
     * Given the fields in a single audit record:
     * - Create an XML audit message according to RFC 3881, including the RFC5425 syslog header.
     * - Create a TLS connection that performs bi-directions certificate authentication,
     *   according to RFC 5425.
     * - Send the XML message on the TLS connection.
     *
     * @param $user
     * @param $group
     * @param $event
     * @param $patient_id
     * @param $outcome
     * @param $comments
     */
    public function sendAtnaAuditMsg($user, $group, $event, $patient_id, $outcome, $comments)
    {
        /* If no ATNA repository server is configured, return */
        if (empty($GLOBALS['atna_audit_host']) || empty($GLOBALS['enable_atna_audit'])) {
            return;
        }

        $host = $GLOBALS['atna_audit_host'];
        $port = $GLOBALS['atna_audit_port'];
        $localcert = $GLOBALS['atna_audit_localcert'];
        $cacert = $GLOBALS['atna_audit_cacert'];
        $conn = $this->createTlsConn($host, $port, $localcert, $cacert);
        if ($conn !== false) {
            $msg = $this->createRfc3881Msg($user, $group, $event, $patient_id, $outcome, $comments);
            fwrite($conn, $msg);
            fclose($conn);
        }
    }

    /**
     * Add an entry into the audit log table, indicating that an
     * SQL query was performed. $outcome is true if the statement
     * successfully completed.  Determine the event type based on
     * the tables present in the SQL query.
     *
     * @param $statement
     * @param $outcome
     * @param null      $binds
     */
    public function auditSQLEvent($statement, $outcome, $binds = null)
    {

        // Set up crypto object that will be used by this singleton class for for encryption/decryption (if not set up already)
        if (!isset($this->cryptoGen)) {
            $this->cryptoGen = new CryptoGen();
        }

        $user =  $_SESSION['authUser'] ?? "";

        /* Don't log anything if the audit logging is not enabled. Exception for "emergency" users */
        if (!isset($GLOBALS['enable_auditlog']) || !($GLOBALS['enable_auditlog'])) {
            if (!$GLOBALS['gbl_force_log_breakglass'] || !$this->isBreakglassUser($user)) {
                return;
            }
        }

        $statement = trim($statement);

        if ((stripos($statement, "insert into log") !== false)  // avoid infinite loop
            || (stripos($statement, "FROM log ") !== false)     // avoid infinite loop
            || (strpos($statement, "sequences") !== false)      // Don't log sequences - to avoid the affect due to GenID calls
            || (stripos($statement, "SELECT count(") === 0)     // Skip SELECT count() statements.
        ) {
            return;
        }

        /* Determine the query type (select, update, insert, delete) */
        $querytype = "select";
        $querytypes = array("select", "update", "insert", "delete","replace");
        foreach ($querytypes as $qtype) {
            if (stripos($statement, $qtype) === 0) {
                $querytype = $qtype;
                break;
            }
        }

        /* If query events are not enabled, don't log them. Exception for "emergency" users. */
        if (($querytype == "select") && !(array_key_exists('audit_events_query', $GLOBALS) && $GLOBALS['audit_events_query'])) {
            if (!$GLOBALS['gbl_force_log_breakglass'] || !$this->isBreakglassUser($user)) {
                return;
            }
        }

        $comments = $statement;

        if (is_array($binds)) {
            // Need to include the binded variable elements in the logging
            $processed_binds = "";
            foreach ($binds as $value_bind) {
                $processed_binds .= "'" . add_escape_custom($value_bind) . "',";
            }
            rtrim($processed_binds, ',');

            if (!empty($processed_binds)) {
                $comments .= " (" . $processed_binds . ")";
            }
        }

        /* Determine the audit event based on the database tables */
        $event = "other";
        $category = "other";

        /* When searching for table names, truncate the SQL statement,
         * removing any WHERE, SET, or VALUE clauses.
         */
        $truncated_sql = $statement;
        $truncated_sql = str_replace("\n", " ", $truncated_sql);
        if ($querytype == "select") {
            $startwhere = stripos($truncated_sql, " where ");
            if ($startwhere > 0) {
                $truncated_sql = substr($truncated_sql, 0, $startwhere);
            }
        } else {
            $startparen = stripos($truncated_sql, "(");
            $startset = stripos($truncated_sql, " set ");
            $startvalues = stripos($truncated_sql, " values ");

            if ($startparen > 0) {
                $truncated_sql = substr($truncated_sql, 0, $startparen);
            }

            if ($startvalues > 0) {
                $truncated_sql = substr($truncated_sql, 0, $startvalues);
            }

            if ($startset > 0) {
                $truncated_sql = substr($truncated_sql, 0, $startset);
            }
        }

        foreach (self::LOG_TABLES as $table => $value) {
            if (strpos($truncated_sql, $table) !== false) {
                $event = $value;
                $category = $this->eventCategoryFinder($comments, $event, $table);
                break;
            } else if (strpos($truncated_sql, "form_") !== false) {
                $event = "patient-record";
                $category = $this->eventCategoryFinder($comments, $event, $table);
                break;
            }
        }

        /* Avoid filling the audit log with trivial SELECT statements.
         * Skip SELECTs from unknown tables.
         */
        if ($querytype == "select") {
            if ($event == "other") {
                return;
            }
        }

        /* If the event is a patient-record, then note the patient id */
        $pid = 0;
        if ($event == "patient-record") {
            if (array_key_exists('pid', $_SESSION) && $_SESSION['pid'] != '') {
                $pid = $_SESSION['pid'];
            }
        }

        if (!($GLOBALS["audit_events_${event}"])) {
            if (!$GLOBALS['gbl_force_log_breakglass'] || !$this->isBreakglassUser($user)) {
                return;
            }
        }

        $event = $event . "-" . $querytype;

        /**
         * @var $adodb \ADODB_mysqli|\ADOConnection
         */
        $adodb = $GLOBALS['adodb']['db'];

        $encrypt_comment = 'No';
        //July 1, 2014: Ensoftek: Check and encrypt audit logging
        if (array_key_exists('enable_auditlog_encryption', $GLOBALS) && $GLOBALS["enable_auditlog_encryption"]) {
            $comments =  $this->cryptoGen->encryptStandard($comments);
            $encrypt_comment = 'Yes';
        }

        $group = $_SESSION['authProvider'] ?? "";
        $success = (int)($outcome !== false);
        $checksum = ($outcome !== false) ? $this->sqlChecksumOfModifiedRow($statement) : '';

        $current_datetime = date("Y-m-d H:i:s");
        $SSL_CLIENT_S_DN_CN = $_SERVER['SSL_CLIENT_S_DN_CN'] ?? '';
        $sql = "insert into log (date, event,category, user, groupname, comments, patient_id, success, checksum,crt_user) " .
            "values ( ".
            $adodb->qstr($current_datetime). ", ".
            $adodb->qstr($event) . ", " .
            $adodb->qstr($category) . ", " .
            $adodb->qstr($user) . "," .
            $adodb->qstr($group) . "," .
            $adodb->qstr($comments) . "," .
            $adodb->qstr($pid) . "," .
            $adodb->qstr($success) . "," .
            $adodb->qstr($checksum) . "," .
            $adodb->qstr($SSL_CLIENT_S_DN_CN) .")";
        sqlInsertClean_audit($sql);

        $last_log_id = $GLOBALS['adodb']['db']->Insert_ID();
        $checksumGenerate = '';
        //July 1, 2014: Ensoftek: Record the encryption checksum in a secondary table(log_comment_encrypt)
        if ($querytype == 'update') {
            $concatLogColumns = $current_datetime.$event.$user.$group.$comments.$pid.$success.$checksum.$SSL_CLIENT_S_DN_CN;
            $checksumGenerate = sha1($concatLogColumns);
        }

        $encryptLogQry = "INSERT INTO log_comment_encrypt (log_id, encrypt, checksum, `version`) ".
            " VALUES ( ".
            $adodb->qstr($last_log_id) . "," .
            $adodb->qstr($encrypt_comment) . "," .
            $adodb->qstr($checksumGenerate) .", '3')";
        sqlInsertClean_audit($encryptLogQry);

        $this->sendAtnaAuditMsg($user, $group, $event, $pid, $success, $comments);
    }

    /**
     * May-29-2014: Ensoftek: For Auditable events and tamper-resistance (MU2)
     * Insert Audit Logging Status into the LOG table.
     *
     * @param $enable
     */
    public function auditSQLAuditTamper($setting, $enable)
    {
        $user =  $_SESSION['authUser'] ?? "";
        $group = $_SESSION['authProvider'] ?? "";
        $pid = 0;
        $checksum = "";
        $success = 1;
        $event = "security-administration" . "-" . "insert";


        $adodb = $GLOBALS['adodb']['db'];

        if ($setting == 'enable_auditlog') {
            $comments = "Audit Logging";
        } else if ($setting == 'gbl_force_log_breakglass') {
            $comments = "Force Breakglass Logging";
        } else {
            $comments = $setting;
        }

        if ($enable == "1") {
            $comments .= " Enabled.";
        } else {
            $comments .= " Disabled.";
        }

        $SSL_CLIENT_S_DN_CN=isset($_SERVER['SSL_CLIENT_S_DN_CN']) ? $_SERVER['SSL_CLIENT_S_DN_CN'] : '';
        $sql = "insert into log (date, event, user, groupname, comments, patient_id, success, checksum,crt_user) " .
            "values ( NOW(), " .
            $adodb->qstr($event) . ", " .
            $adodb->qstr($user) . "," .
            $adodb->qstr($group) . "," .
            $adodb->qstr($comments) . "," .
            $adodb->qstr($pid) . "," .
            $adodb->qstr($success) . "," .
            $adodb->qstr($checksum) . "," .
            $adodb->qstr($SSL_CLIENT_S_DN_CN) .")";

        sqlInsertClean_audit($sql);
        $this->sendAtnaAuditMsg($user, $group, $event, $pid, $success, $comments);
    }

    /**
     * Record the patient disclosures.
     *
     * @param $dates    - The date when the disclosures are sent to the thrid party.
     * @param $event    - The type of the disclosure.
     * @param $pid      - The id of the patient for whom the disclosures are recorded.
     * @param $comment  - The recipient name and description of the disclosure.
     * @uname - The username who is recording the disclosure.
     */
    public function recordDisclosure($dates, $event, $pid, $recipient, $description, $user)
    {
        $adodb = $GLOBALS['adodb']['db'];
        $crt_user= $_SERVER['SSL_CLIENT_S_DN_CN'];
        $groupname=$_SESSION['authProvider'];
        $success=1;
        $sql = "insert into extended_log ( date, event, user, recipient, patient_id, description) " .
            "values (" . $adodb->qstr($dates) . "," . $adodb->qstr($event) . "," . $adodb->qstr($user) .
            "," . $adodb->qstr($recipient) . ",".
            $adodb->qstr($pid) ."," .
            $adodb->qstr($description) .")";
        $ret = sqlInsertClean_audit($sql);
    }

    /**
     * Edit the disclosures that is recorded.
     *
     * @param $dates  - The date when the disclosures are sent to the thrid party.
     * @param $event  - The type of the disclosure.
     * param $comment - The recipient and the description of the disclosure are appended.
     * $logeventid    - The id of the record which is to be edited.
     */
    public function updateRecordedDisclosure($dates, $event, $recipient, $description, $disclosure_id)
    {
        $adodb = $GLOBALS['adodb']['db'];
        $sql="update extended_log set
                event=" . $adodb->qstr($event) . ",
                date=" .  $adodb->qstr($dates) . ",
                recipient=" . $adodb->qstr($recipient) . ",
                description=" . $adodb->qstr($description) . "
                where id=" . $adodb->qstr($disclosure_id) . "";
        $ret = sqlInsertClean_audit($sql);
    }

    /**
     * Delete the disclosures that is recorded.
     *
     * @param $deletelid - The id of the record which is to be deleted.
     */
    public function deleteDisclosure($deletelid)
    {
        $sql = "delete from extended_log where id='" . add_escape_custom($deletelid) . "'";
        $ret = sqlInsertClean_audit($sql);
    }

    /**
     * July 1, 2014: Ensoftek: Utility function to get data from table(log_comment_encrypt)
     *
     * @param  $log_id
     * @return array
     */
    public function logCommentEncryptData($log_id)
    {
        $encryptRow = array();
        $logRes = sqlStatement("SELECT * FROM log_comment_encrypt WHERE log_id=?", array($log_id));
        while ($logRow = sqlFetchArray($logRes)) {
            $encryptRow['encrypt'] = $logRow['encrypt'];
            $encryptRow['checksum'] = $logRow['checksum'];
            $encryptRow['version'] = $logRow['version'];
        }

        return $encryptRow;
    }

    /**
     * Function used to determine category of the event
     *
     * @param  $sql
     * @param  $event
     * @param  $table
     * @return string
     */
    private function eventCategoryFinder($sql, $event, $table)
    {
        if ($event == 'delete') {
            if (strpos($sql, "lists:") === 0) {
                $fieldValues    = explode("'", $sql);
                if (in_array('medical_problem', $fieldValues) === true) {
                    return 'Problem List';
                } else if (in_array('medication', $fieldValues) === true) {
                    return 'Medication';
                } else if (in_array('allergy', $fieldValues) === true) {
                    return 'Allergy';
                }
            }
        }

        if ($table == 'lists' || $table == 'lists_touch') {
            $trimSQL        = stristr($sql, $table);
            $fieldValues    = explode("'", $trimSQL);
            if (in_array('medical_problem', $fieldValues) === true) {
                return 'Problem List';
            } else if (in_array('medication', $fieldValues) === true) {
                return 'Medication';
            } else if (in_array('allergy', $fieldValues) === true) {
                return 'Allergy';
            }
        } else if ($table == 'immunizations') {
            return "Immunization";
        } else if ($table == 'form_vitals') {
            return "Vitals";
        } else if ($table == 'history_data') {
            return "Social and Family History";
        } else if ($table == 'forms' || $table == 'form_encounter' || strpos($table, 'form_') === 0) {
            return "Encounter Form";
        } else if ($table == 'insurance_data') {
            return "Patient Insurance";
        } else if ($table == 'patient_data' || $table == 'employer_data') {
            return "Patient Demographics";
        } else if ($table == 'payments' || $table == "billing" || $table == "claims") {
            return "Billing";
        } else if ($table == 'pnotes') {
            return "Clinical Mail";
        } else if ($table == 'prescriptions') {
            return "Medication";
        } else if ($table == 'transactions') {
            $trimSQL        = stristr($sql, "transactions");
            $fieldValues    = explode("'", $trimSQL);
            if (in_array("LBTref", $fieldValues)) {
                return "Referral";
            } else {
                return $event;
            }
        } else if ($table == 'amendments' || $table == 'amendments_history') {
            return "Amendments";
        } else if ($table == 'openemr_postcalendar_events') {
            return "Scheduling";
        } else if ($table == 'procedure_order' || $table == 'procedure_order_code') {
            return "Lab Order";
        } else if ($table == 'procedure_report' || $table == 'procedure_result') {
            return "Lab Result";
        } else if ($event == 'security-administration') {
            return "Security";
        }

        return $event;
    }

    // Goal of this function is to increase performance in logging engine to check
    //  if a user is a breakglass user (in this case, will log all activities if the
    //  setting is turned on in Administration->Logging->'Audit all Emergency User Queries').
    private function isBreakglassUser($user)
    {
        // return false if $user is empty
        if (empty($user)) {
            return false;
        }

        // Return the breakglass user flag if it exists already (it is cached by this singleton class to speed the logging engine up)
        if (isset($this->breakglassUser)) {
            return $this->breakglassUser;
        }

        // see if current user is in the breakglass group
        //  note we are bypassing gacl standard api to improve performance
        $queryUser = sqlQueryNoLog(
            "SELECT `gacl_aro`.`value`
            FROM `gacl_aro`, `gacl_groups_aro_map`, `gacl_aro_groups`
            WHERE `gacl_aro`.`id` = `gacl_groups_aro_map`.`aro_id`
            AND `gacl_groups_aro_map`.`group_id` = `gacl_aro_groups`.`id`
            AND `gacl_aro_groups`.`value` = 'breakglass'
            AND BINARY `gacl_aro`.`value` = ?",
            [$user]
        );
        if (empty($queryUser)) {
            // user is not in breakglass group
            $this->breakglassUser = false;
        } else {
            // user is in breakglass group
            $this->breakglassUser = true;
        }
        return $this->breakglassUser;
    }
}
