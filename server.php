<?php
/**
 *
 * This file is part of openLibrary.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * openLibrary is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * openLibrary is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with openLibrary.  If not, see <http://www.gnu.org/licenses/>.
*/


require_once("OLS_class_lib/webServiceServer_class.php");
require_once("OLS_class_lib/oci_class.php");

class openNumberRoll extends webServiceServer {
private $roll_name;

    public function numberRoll($param) {
        if (!$this->aaa->has_right("opennumberroll", 500)) {
            $res->error->_value = "authentication_error";
        } 
        else {
          $this->roll_name = $param->numberRollName->_value;
          $valid_rolls = $this->config->get_value("valid_number_roll","setup");
          if (in_array($this->roll_name, $valid_rolls)) {
// hack for creating test faust numbers until final scheme is found
              if (self::is_faust($this->roll_name)) {
                $ret->numberRollResponse->_value = self::create_test_faust();
                return $ret;
              }
              define('CONNECT_LOOPS', 2); // should be 1, but history tells otherwise
              for ($i = 1; ($i <= CONNECT_LOOPS) && !is_object($oci); $i++) {
                $oci = self::get_oci_connection($this->config->get_value("numberroll_credentials","setup"), $i);
              }
              if (!is_object($oci)) {
                $res->error->_value = "error_reaching_database";
              }
              else {
                do {
                  if ($next_val = self::get_next_val($oci, $this->roll_name)) {
                    if (self::is_faust($this->roll_name)) {
                      $next_val = self::modify_faust_next_val($next_val);
                    }
                    $res->rollNumber->_value = $next_val;
                  }
                  else {
                    $res->error->_value = "error_creating_number_roll";
                  } 
                } 
                while (empty($res->rollNumber->_value) && (empty($res->error->_value)));
              }
          }
          else {
            $res->error->_value = "unknown_number_roll_name";
          }
        }


        //var_dump($res); var_dump($param); die();
        $ret->numberRollResponse->_value = $res;
        return $ret;

    }

    private function is_faust($name) {
      return $name == 'faust';
    }

    private function create_test_faust() {
      do {
        $stem = strval(rand(1000000, 8999999));
        $check = self::calculate_check($stem);
      }
      while (!is_int($check));
      return $stem . strval($check);
    }

    private function modify_faust_next_val($next_val) {
      if ($check = self::calculate_check($next_val)) {
        return $next_val . $check;
      }
      else {
        return NULL;
      }
    }

    private function calculate_check($str) {
      $wgt = '765432765432765432';
      $str18 = sprintf('%018s', $str);
      for ($i = strlen($str18); $i; $i--) {
        $sum += intval($str18[$i - 1]) * intval($wgt[$i - 1]);
      }
      $chk = 11 - ($sum % 11);
      return ($chk < 10 ? $chk : FALSE);
    }

    private function get_next_val($oci, $roll_name) {
      $this->watch->start('nextval');
      try {
          $oci->set_query("SELECT " . $roll_name . ".nextval FROM dual");
          $val = $oci->fetch_into_assoc();
          if (empty($val["NEXTVAL"])) {
              verbose::log(FATAL, "OpenNumberRoll:: Got no number?? error: " . $oci->get_error_string());
              $ret = FALSE;
          } else {
              verbose::log(TRACE, "OpenNumberRoll:: " . $roll_name . " returned number: " . $val["NEXTVAL"]);
              $ret = $val["NEXTVAL"];
          }
      } catch (ociException $e) {
          verbose::log(FATAL, "OpenNumberRoll:: OCI select error: " . $oci->get_error_string());
          $ret = FALSE;
      }
      $this->watch->stop('nextval');
      return $ret;
    }

    private function get_oci_connection($credentials, $attempt) {
      $oci = new Oci($credentials);
      $oci->set_charset("UTF8");
      $this->watch->start('connect-' . $attempt);
      try {
          $oci->connect();
          return $oci;
      }
      catch (ociException $e) {
          verbose::log(FATAL, 'OpenNumberRoll:: OCI connect error #' . $attempt . ': ' . $oci->get_error_string());
      }
      return FALSE;
    }

}

/*
 * MAIN
 */

$ws=new openNumberRoll('opennumberroll.ini');
$ws->handle_request();

?>

