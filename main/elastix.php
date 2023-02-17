<?php
include_once dirname(__FILE__) . "/lib/ini/ini_handler.php";
include_once dirname(__FILE__) . "/lib/json/phpJson.class.php";
include_once dirname(__FILE__) . "/lib/ast/Extension.php";
class Elastix
{
    public function __construct()
    {
        $fh = fopen("/etc/issabel.conf", "r");
        $data = [];
        while ($line = fgets($fh)) {
            if (strlen($line) > 1) {
                $doarr = split("=", $line);
                $passwd = (string) $doarr[1];
                $passwd = str_replace("\n", "", $passwd);
                $data[(string) $doarr[0]] = $passwd;
            }
        }
        fclose($fh);
        $this->hostname = "localhost";
        $this->username = "root";
        $this->password = $data["mysqlrootpwd"];
        $this->db = null;
    }
    public function __destruct()
    {
        try {
            $this->db = null;
        } catch (PDOException $e) {
            echo $e->getMessage();
        }
    }
    private function _get_db_connection($dbname)
    {
        try {
            $this->db = new PDO(
                "mysql:host=" .
                    $this->hostname .
                    ";dbname=" .
                    $dbname .
                    ";charset=utf8",
                $this->username,
                $this->password
            );
            $this->db->query("SET CHARACTER SET utf8");
        } catch (PDOException $e) {
            echo $e->getMessage();
        }
    }
    public function _cdr_where_expression(
        $start_date,
        $end_date,
        $field_name,
        $field_pattern,
        $status,
        $custom
    ) {
        $where = "";
        if (!is_null($start_date) && !is_null($end_date)) {
            $where .= "(calldate BETWEEN '$start_date' AND '$end_date')";
        }

        if (!is_null($field_name) && !is_null($field_pattern)) {
            $where = empty($where) ? $where : "$where AND ";
            $where .= "($field_name LIKE '%$field_pattern%')";
        }

        $where = empty($where) ? $where : "$where AND ";
        $where .=
            is_null($status) || empty($status) || $status === "ALL"
                ? "(disposition IN ('ANSWERED', 'BUSY', 'FAILED', 'NO ANSWER'))"
                : "(disposition = '$status')";
        $where .= " AND dst != 's' ";

        if (!is_null($custom)) {
            $where .= $custom;
        }

        return $where;
    }
    public function get_cdr()
    {
        try {
            $this->_get_db_connection("asteriskcdrdb");
            $start_date = $_POST["start_date"];
            $end_date = $_POST["end_date"];
            $field_name = $_POST["field_name"];
            $field_pattern = $_POST["field_pattern"];
            $status = $_POST["status"];
            $limit = isset($_GET["limit"]) ? $_GET["limit"] : 100;
            $custom = $_POST["custom"];
            $where_expression = $this->_cdr_where_expression(
                $start_date,
                $end_date,
                $field_name,
                $field_pattern,
                $status,
                $custom
            );
            $sql_cmd = "SELECT date_format(calldate, '%Y/%m/%d %H:%i:%s' ) as calldatetime ,src,dst,disposition,uniqueid,userfield,recordingfile FROM cdr WHERE $where_expression ORDER BY calldate DESC LIMIT $limit";
            $stmt = $this->db->prepare($sql_cmd);
            $stmt->execute();
            $result = (array) $stmt->fetchAll(PDO::FETCH_ASSOC);
            header("Content-Type: application/json");
            echo json_encode($result);
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    public function get_cdr_ext()
    {
        try {
            $this->_get_db_connection("asteriskcdrdb");
            $start_date = $_GET["start_date"];
            $end_date = $_GET["end_date"];
            $ext = $_GET["ext"];
            $limit = isset($_GET["limit"]) ? $_POST["GET"] : 100;
            $where_expression =
                "(calldate BETWEEN '" .
                "$start_date" .
                "' AND '" .
                "$end_date" .
                "')AND (src = '" .
                "$ext" .
                "' or dst = '" .
                "$ext" .
                "')";
            $sql_cmd = "SELECT date_format(calldate, '%Y/%m/%d' ) as Startdate,date_format(calldate, '%H:%i:%s' ) as Starttime ,src,dst,disposition,duration,uniqueid,userfield,recordingfile FROM cdr WHERE $where_expression  ORDER BY calldate DESC LIMIT $limit";
            $stmt = $this->db->prepare($sql_cmd);
            $stmt->execute();
            $result = (array) $stmt->fetchAll(PDO::FETCH_ASSOC);
            header("Content-Type: application/json");
            echo json_encode($result);
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    public function get_wav_file()
    {
        /*
			$name = "/2015/12/08/out-05355620760-101-20151208-102449-1449563089.106.wav";
		*/
        $name = $_GET["name"];
        $directory = "/calls/recordings/";
        $file = realpath($directory . $name);
        if (
            strpos($file, $directory) !== false &&
            strpos($file, $directory) == 0 &&
            file_exists($file) &&
            is_file($file)
        ) {
            header(
                "Content-Disposition: attachment; filename=\"" .
                    basename($file) .
                    "\""
            );
            header("Content-Length: " . filesize($file));
            header("Content-Type: application/octet-stream;");
            readfile($file);
        } else {
            header("HTTP/1.0 404 Not Found");
            header("Content-Type: application/json");
            echo '{"status": "File not found", "code": 404}';
        }
    }
    public function get_harddrivers()
    {
        $main_arr = [];
        exec("df -H /", $harddisk);
        exec("du -sh /var/log", $logs);
        exec("du -sh /opt", $thirdparty);
        exec("du -sh /var/spool/asterisk/voicemail", $voicemails);
        exec("du -sh /var/www/backup", $backups);
        exec("du -sh /etc", $configuration);
        exec("du -sh /var/spool/asterisk/monitor", $recording);
        $hard_arr = [];
        $tmp_arr = explode(
            " ",
            trim(preg_replace("/\s\s+/", " ", $harddisk[2]))
        );
        $hard_arr["size"] = $tmp_arr[0];
        $hard_arr["used"] = $tmp_arr[1];
        $hard_arr["avail"] = $tmp_arr[2];
        $hard_arr["usepercent"] = $tmp_arr[3];
        $hard_arr["mount"] = $tmp_arr[4];
        $main_arr["harddisk"] = $hard_arr;
        $main_arr["logs"] = explode("\t", $logs[0]);
        $main_arr["thirdparty"] = explode("\t", $thirdparty[0]);
        $main_arr["voicemails"] = explode("\t", $voicemails[0]);
        $main_arr["backups"] = explode("\t", $backups[0]);
        $main_arr["configuration"] = explode("\t", $configuration[0]);
        $main_arr["recording"] = explode("\t", $recording[0]);
        header("Content-Type: application/json");
        echo json_encode($main_arr);
    }
    public function get_iptables_status()
    {
        $exist = "false";
        $pid = shell_exec("sudo /sbin/service iptables status 2>&1");
        if (strlen($pid) > 100) {
            $exist = "true";
        }
        header("Content-Type: application/json");
        echo '{"pid": "' . $pid . '", "is_exist": ' . $exist . "}";
    }
    private function apply_config()
    {
        exec("/var/lib/asterisk/bin/module_admin reload", $data);
    }
    public function add_sip_extension()
    {
        $this->_get_db_connection("asterisk");
        $dict = [
            "name" => $_POST["name"],
            "deny" => $_POST["deny"],
            "secret" => $_POST["secret"],
            "dtmfmode" => $_POST["dtmfmode"],
            "canreinvite" => $_POST["canreinvite"],
            "context" => $_POST["context"],
            "host" => $_POST["host"],
            "trustrpid" => $_POST["trustrpid"],
            "sendrpid" => $_POST["sendrpid"],
            "type" => $_POST["type"],
            "nat" => $_POST["nat"],
            "port" => $_POST["port"],
            "qualify" => $_POST["qualify"],
            "qualifyfreq" => $_POST["qualifyfreq"],
            "transport" => $_POST["transport"],
            "avpf" => $_POST["avpf"],
            "icesupport" => $_POST["icesupport"],
            "encryption" => $_POST["encryption"],
            "callgroup" => $_POST["callgroup"],
            "pickupgroup" => $_POST["pickupgroup"],
            "dial" => $_POST["dial"],
            "mailbox" => $_POST["mailbox"],
            "permit" => $_POST["permit"],
            "callerid" => $_POST["callerid"],
            "callcounter" => $_POST["callcounter"],
            "faxdetect" => $_POST["faxdetect"],
            "account" => $_POST["account"],
        ];
        $ext = new Extension($dict, "insert");
        $stmt0 = $this->db->prepare($ext->select_sip_sqlscript());
        $stmt0->execute();
        $row = $stmt0->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $stmt1 = $this->db->exec($ext->insert_into_users_sqlscript());
            $stmt2 = $this->db->exec($ext->insert_into_devices_sqlscript());
            $stmt3 = $this->db->exec($ext->insert_into_sip_sqlscript());
            $this->apply_config();
        }
        header("Content-Type: application/json");
        echo '{"status": "INSERT OK", "code": 200}';
    }
    public function update_sip_extension()
    {
        $this->_get_db_connection("asterisk");
        $dict = [
            "name" => $_POST["name"],
            "deny" => $_POST["deny"],
            "secret" => $_POST["secret"],
            "dtmfmode" => $_POST["dtmfmode"],
            "canreinvite" => $_POST["canreinvite"],
            "context" => $_POST["context"],
            "host" => $_POST["host"],
            "trustrpid" => $_POST["trustrpid"],
            "sendrpid" => $_POST["sendrpid"],
            "type" => $_POST["type"],
            "nat" => $_POST["nat"],
            "port" => $_POST["port"],
            "qualify" => $_POST["qualify"],
            "qualifyfreq" => $_POST["qualifyfreq"],
            "transport" => $_POST["transport"],
            "avpf" => $_POST["avpf"],
            "icesupport" => $_POST["icesupport"],
            "encryption" => $_POST["encryption"],
            "callgroup" => $_POST["callgroup"],
            "pickupgroup" => $_POST["pickupgroup"],
            "dial" => $_POST["dial"],
            "mailbox" => $_POST["mailbox"],
            "permit" => $_POST["permit"],
            "callerid" => $_POST["callerid"],
            "callcounter" => $_POST["callcounter"],
            "faxdetect" => $_POST["faxdetect"],
            "account" => $_POST["account"],
        ];
        $ext = new Extension($dict, "update");
        $stmt1 = $this->db->exec($ext->update_sip_sqlscript());
        $stmt2 = $this->db->exec($ext->update_users_sqlscript());
        $this->apply_config();
        header("Content-Type: application/json");
        echo '{"status": "UPDATE OK", "code": 200}';
    }
    public function delete_sip_extension()
    {
        $this->_get_db_connection("asterisk");
        $dict = ["account" => $_POST["account"]];
        $ext = new Extension($dict, "delete");
        $stmt1 = $this->db->exec($ext->delete_sip_sqlscript());
        $stmt2 = $this->db->exec($ext->delete_users_sqlscript());
        $stmt3 = $this->db->exec($ext->delete_devices_sqlscript());
        $this->apply_config();
        header("Content-Type: application/json");
        echo '{"status": "DELETE OK", "code": 200}';
    }
    private function apply_retrieve()
    {
        exec("/var/lib/asterisk/bin/retrieve_conf", $data);
    }
    private function show_ampuser($dict)
    {
        exec(
            '/usr/sbin/asterisk -rx "database show AMPUSER ' .
                $dict["grpnum"] .
                "/followme"
        );
    }
    private function put_ampuser($dict)
    {
        exec(
            '/usr/sbin/asterisk -rx "database put AMPUSER ' .
                $dict["grpnum"] .
                '/followme/changecid default"'
        );
        exec(
            '/usr/sbin/asterisk -rx "database put AMPUSER ' .
                $dict["grpnum"] .
                '/followme/ddial DIRECT"'
        );
        exec(
            '/usr/sbin/asterisk -rx "database put AMPUSER ' .
                $dict["grpnum"] .
                '/followme/fixedcid "'
        );
        exec(
            '/usr/sbin/asterisk -rx "database put AMPUSER ' .
                $dict["grpnum"] .
                '/followme/grpconf ENABLED"'
        );
        exec(
            '/usr/sbin/asterisk -rx "database put AMPUSER ' .
                $dict["grpnum"] .
                "/followme/grplist " .
                $dict["grplist"] .
                '"'
        );
        exec(
            '/usr/sbin/asterisk -rx "database put AMPUSER ' .
                $dict["grpnum"] .
                "/followme/grptime " .
                $dict["grptime"] .
                '"'
        );
        exec(
            '/usr/sbin/asterisk -rx "database put AMPUSER ' .
                $dict["grpnum"] .
                "/followme/prering " .
                $dict["pre_ring"] .
                '"'
        );
    }
    private function deltree_ampuser($dict)
    {
        exec(
            '/usr/sbin/asterisk -rx "database deltree AMPUSER ' .
                $dict["grpnum"] .
                '/followme"'
        );
    }
    public function add_followme_extension()
    {
        $this->_get_db_connection("asterisk");
        $dict = [
            "grpnum" => $_POST["grpnum"],
            "strategy" => $_POST["strategy"],
            "grptime" => $_POST["grptime"],
            "grppre" => $_POST["grppre"],
            "grplist" => $_POST["grplist"],
            "annmsg_id" => $_POST["annmsg_id"],
            "postdest" => $_POST["postdest"],
            "dring" => $_POST["dring"],
            "remotealert_id" => $_POST["remotealert_id"],
            "needsconf" => $_POST["needsconf"],
            "toolate_id" => $_POST["toolate_id"],
            "pre_ring" => $_POST["pre_ring"],
            "ringing" => $_POST["ringing"],
        ];
        $this->put_ampuser($dict);
        $find = new FindMeFollow($dict, "insert");
        $stmt0 = $this->db->prepare($find->select_findmefollow_sqlscript());
        $stmt0->execute();
        $row = $stmt0->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $stmt1 = $this->db->exec(
                $find->insert_into_findmefollow_sqlscript()
            );
            $this->apply_retrieve();
            $this->apply_config();
        }
        header("Content-Type: application/json");
        echo '{"status": "INSERT OK", "code": 200}';
    }
    public function update_followme_extension()
    {
        $this->_get_db_connection("asterisk");
        $dict = [
            "grpnum" => $_POST["grpnum"],
            "strategy" => $_POST["strategy"],
            "grptime" => $_POST["grptime"],
            "grppre" => $_POST["grppre"],
            "grplist" => $_POST["grplist"],
            "annmsg_id" => $_POST["annmsg_id"],
            "postdest" => $_POST["postdest"],
            "dring" => $_POST["dring"],
            "remotealert_id" => $_POST["remotealert_id"],
            "needsconf" => $_POST["needsconf"],
            "toolate_id" => $_POST["toolate_id"],
            "pre_ring" => $_POST["pre_ring"],
            "ringing" => $_POST["ringing"],
        ];
        $this->put_ampuser($dict);
        $find = new FindMeFollow($dict, "update");
        $stmt1 = $this->db->exec($find->update_findmefollow_sqlscript());
        $this->apply_retrieve();
        $this->apply_config();
        header("Content-Type: application/json");
        echo '{"status": "UPDATE OK", "code": 200}';
    }
    public function delete_followme_extension()
    {
        $this->_get_db_connection("asterisk");
        $dict = ["grpnum" => $_POST["grpnum"]];
        $this->deltree_ampuser($dict);
        $find = new FindMeFollow($dict, "delete");
        $stmt1 = $this->db->exec($find->delete_findmefollow_sqlscript());
        $this->apply_retrieve();
        $this->apply_config();
        header("Content-Type: application/json");
        echo '{"status": "DELETE OK", "code": 200}';
    }
    public function view_followme_extension()
    {
        $this->_get_db_connection("asterisk");
        $dict = ["grpnum" => $_POST["grpnum"]];
        $find = new FindMeFollow($dict, "select");
        $stmt1 = $this->db->prepare($find->select_findmefollow_sqlscript());
        $stmt1->execute();
        $result = (array) $stmt1->fetchAll(PDO::FETCH_ASSOC);
        header("Content-Type: application/json");
        echo json_encode($result);
    }
    public function view_followme_all_extensions()
    {
        $this->_get_db_connection("asterisk");
        $dict = [];
        $find = new FindMeFollow($dict, "selectall");
        $stmt1 = $this->db->prepare($find->select_all_findmefollow_sqlscript());
        $stmt1->execute();
        $result = (array) $stmt1->fetchAll(PDO::FETCH_ASSOC);
        header("Content-Type: application/json");
        echo json_encode($result);
    }

    public function click_to_call()
    {
        $strHost = "127.0.0.1";
        $strUser = "smsgateway";
        $strSecret = "Law@sms";
        $strChannel = "SIP/" . $_GET["ext"];
        $strExt = $_GET["ext"];
        $strContext = "from-internal";

        $strWaitTime = "30";

        $strPriority = "1";
        $strMaxRetry = "2";
        $meta = $_GET["meta"];
        $number = strtolower($_GET["number"]);
        $pos = strpos($number, "local");
        if ($number == null):
            exit();
        endif;
        if ($pos === false):
            $errno = 0;
            $errstr = 0;
            $strCallerId = "Call To $number";
            $oSocket = fsockopen("localhost", 5038, $errno, $errstr, 20);
            if (!$oSocket) {
                echo "$errstr ($errno)<br>\n";
            } else {
                fputs($oSocket, "Action: login\r\n");
                fputs($oSocket, "Events: off\r\n");
                fputs($oSocket, "Username: $strUser\r\n");
                fputs($oSocket, "Secret: $strSecret\r\n\r\n");
                fputs($oSocket, "Action: originate\r\n");
                fputs($oSocket, "Channel: $strChannel\r\n");
                fputs($oSocket, "WaitTime: $strWaitTime\r\n");
                fputs($oSocket, "CallerId: $strCallerId\r\n");
                fputs($oSocket, "Exten: $number\r\n");
                fputs($oSocket, "Context: $strContext\r\n");

                fputs($oSocket, "Variable: __exth=$strExt\r\n");
                fputs($oSocket, "Variable: __meta=$meta\r\n");

                fputs($oSocket, "Priority: $strPriority\r\n\r\n");
                fputs($oSocket, "Action: Logoff\r\n\r\n");
                sleep(2);
                fclose($oSocket);
            }
            //echo "Extension $strChannel should be calling $number.";
            header("HTTP/1.1 200 OK");
            header("Content-Type: application/json");
            echo '{"status": "OK", "code": 200}';
        else:
            header("HTTP/1.0 404 Not Found");
            header("Content-Type: application/json");
            echo '{"status": "Error", "code": 404}';
            exit();
        endif;
    }

    public function get_wav_file_meta()
    {
        $this->_get_db_connection("asteriskcdrdb");
        $meta = "'" . $_GET["meta"] . "'";
        $sql_cmd = "SELECT date_format(calldate, '%Y/%m/%d' ) as Startdate,recordingfile FROM cdr WHERE userfield = $meta";
        $stmt = $this->db->prepare($sql_cmd);
        $stmt->execute();
        $result = (array) $stmt->fetchAll(PDO::FETCH_ASSOC);
        header("Content-Type: application/json");
        $path = $result[0]["Startdate"] . "/" . $result[0]["recordingfile"];

        $directory = "/calls/recordings/";
        $file = realpath($directory . $path);
        if (
            strpos($file, $directory) !== false &&
            strpos($file, $directory) == 0 &&
            file_exists($file) &&
            is_file($file)
        ) {
            header(
                "Content-Disposition: attachment; filename=\"" .
                    basename($file) .
                    "\""
            );
            header("Content-Length: " . filesize($file));
            header("Content-Type: application/octet-stream;");
            readfile($file);
        } else {
            header("HTTP/1.0 404 Not Found");
            header("Content-Type: application/json");
            echo '{"status": "File not found", "code": 404}';
        }
    }
}
?>
