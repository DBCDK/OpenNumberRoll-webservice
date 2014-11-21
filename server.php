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


/** \brief Service that provider different number rolls. 
 * Sequences from oracle or postgress and speciel number rolls like faust with business logic attached to the numbers
 *
 * Valid number rolls and their type are defined in the ini-file
 */
require_once('OLS_class_lib/webServiceServer_class.php');
require_once('OLS_class_lib/pg_database_class.php');

class openNumberRoll extends webServiceServer {
  private $roll_name;
  private $roll_sequence;

  /** \brief - 
   * @param object $param - the users request
   * @retval object - answer as object
   */
  public function numberRoll($param) {
    if (!$this->aaa->has_right('opennumberroll', 500)) {
      $res->error->_value = 'authentication_error';
    } 
    else {
      $this->roll_name = $param->numberRollName->_value;
      $valid_rolls = self::set_valid_rolls($this->config->get_value('valid_number_roll','setup'));
      $is_pg_roll = in_array($this->roll_name, $this->config->get_value('pg_number_roll','setup'));
      if (($roll_sequence = array_search($this->roll_name, $valid_rolls)) !== FALSE) {
        if (self::is_faust_8($this->roll_name)) {
          $ret->numberRollResponse->_value = self::create_faust_8($roll_sequence);
          return $ret;
        }
        // test faust numbers 
        if (self::is_faust_test($this->roll_name)) {
          $ret->numberRollResponse->_value->rollNumber->_value = self::create_random_faust();
          return $ret;
        }
        define('OCI_CONNECT_LOOPS', 2); // should be 1, but history tells otherwise
        do {
          if ($next_val = self::get_next_val($roll_sequence, $is_pg_roll)) {
            if (self::is_faust($this->roll_name)) {
              $next_val = self::modify_faust_next_val($next_val);
            }
            $res->rollNumber->_value = $next_val;
          }
          else {
            $res->error->_value = 'error_creating_number_roll';
          } 
        } 
        while (empty($res->rollNumber->_value) && (empty($res->error->_value)));
      }
      else {
        $res->error->_value = 'unknown_number_roll_name';
      }
    }


    //var_dump($res); var_dump($param); die();
    $ret->numberRollResponse->_value = $res;
    return $ret;

  }

  /** \brief - replace numeric keys with the content of the index
   * @param array $arr -
   * @retval array - 
   */
  private function set_valid_rolls($arr) {
    $ret = array();
    foreach ($arr as $idx => $val) {
      if (is_numeric($idx)) {
        $ret[$val] = $val;
      }
      else {
        $ret[$idx] = $val;
      }
    }
    return $ret;
  }

  /** \brief - return TRUE if 'faust'
   * @param string $name -
   * @retval boolean - 
   */
  private function is_faust($name) {
    return $name == 'faust';
  }

  /** \brief - return TRUE if 'faust_test'
   * @param string $name -
   * @retval boolean - 
   */
  private function is_faust_test($name) {
    return $name == 'faust_test';
  }

  /** \brief - return TRUE if 'faust_8'
   * @param string $name -
   * @retval boolean - 
   */
  private function is_faust_8($name) {
    return $name == 'faust_8';
  }

  /** \brief - get the next faust number 
   * @param object $roll_sequence -
   * @retval object - the created 8 digit faust number - or an error
   */
  private function create_faust_8($roll_sequence) {
    $oci = self::get_oci_connection($this->config->get_value('faust_8_credentials','setup'));
    if (!is_object($oci)) {
      $ret->error->_value = 'error_reaching_database';
    }
    else {
      if ($number = self::get_next_faust_8($oci, $roll_sequence)) {
        $ret->rollNumber->_value = $number;
      }
      else {
        $ret->error->_value = 'error_creating_number_roll';
      } 
    }
    return $ret;
  }

  /** \brief - produce a valid 8 digit faust number from the sequence in "old style"
   * @param resource $oci - the oracle connection
   * @param object $roll_sequence - name of the oracle column to use
   * @retval mixed - the created 8 digit faust number - or FALSE
   */
  private function get_next_faust_8($oci, $roll_sequence) {
    $this->watch->start('faust_8');
    try {
      $oci->bind('bind_rulle_navn', $roll_sequence);
      $oci->set_query('SELECT * FROM nummer_ruller WHERE rulle_navn = :bind_rulle_navn FOR UPDATE');
      $val = $oci->fetch_into_assoc();
      if (self::from_space_number($val['AKTUEL']) < self::from_space_number($val['STARTING'])) {
        $next = self::from_space_number($val['STARTING']);
      }
      else {
        $next = self::from_space_number($val['AKTUEL']);
      }
      $next = self::calc_next($next);
      if ($next > intval(preg_replace('/\D/', '', $val['SLUT']))) {
        verbose::log(FATAL, 'OpenNumberRoll:: Exceeded number_roll: ' . $roll_sequence);
        $ret = FALSE;
      } else {
        $oci->bind('bind_rulle_navn', $roll_sequence);
        $oci->bind('bind_aktuel', self::to_space_number($next));
        $oci->set_query('UPDATE nummer_ruller SET aktuel = :bind_aktuel WHERE rulle_navn = :bind_rulle_navn');
        $val = $oci->get_num_rows();
        verbose::log(TRACE, 'OpenNumberRoll:: ' . $roll_sequence . ' returned number: ' . $next);
        $ret = $next;
        $oci->commit();
      }
    } catch (ociException $e) {
      verbose::log(FATAL, 'OpenNumberRoll:: OCI select error: ' . $oci->get_error_string());
      $ret = FALSE;
      $oci->rollback();
    }
    $this->watch->stop('faust_8');
    return $ret;
  }

  /** \brief - remove non digits and return the first 7 digits of the number
   * @param string $str -
   * @retval string
   */
  private function from_space_number($str) {
    return intval(substr(preg_replace('/\D/', '', $str), 0, 7));
  }

  /** \brief - insert spaces into a faustnumber, to make it the "old style"
   * @param string $str -
   * @retval string - 
   */
  private function to_space_number($str) {
    return substr($str, 0, 1) . ' ' . substr($str, 1, 3) . ' ' . substr($str, 4, 3) . ' ' . substr($str, 7, 1);
  }

  /** \brief - create the next valid number and checkdigit
   * @param string $stem -
   * @retval string - 
   */
  private function calc_next($stem) {
    do {
      $stem++;
      $check = self::calculate_check($stem);
    }
    while (!is_int($check));
    return $stem . strval($check);
  }

  /** \brief - create a random 9 digit number with faust checkdigit
   * @retval string - 
   */
  private function create_random_faust() {
    do {
      $stem = strval(rand(10000000, 89999999));
      $check = self::calculate_check($stem);
    }
    while (!is_int($check));
    return $stem . strval($check);
  }

  /** \brief - add a check digit to a number
     @param string $next_val -
   * @retval mixed - $next_val and check digit or NULL if no check digit can be made from the number 
   */
  private function modify_faust_next_val($next_val) {
    if ($check = self::calculate_check($next_val)) {
      return $next_val . $check;
    }
    else {
      return NULL;
    }
  }

  /** \brief - Calculates the check digit
   * @param string $str -
   * @retval mixed - chekc digit or FALSE
   */
  private function calculate_check($str) {
    $wgt = '765432765432765432';
    $str18 = sprintf('%018s', $str);
    for ($i = strlen($str18); $i; $i--) {
      $sum += intval($str18[$i - 1]) * intval($wgt[$i - 1]);
    }
    $chk = 11 - ($sum % 11);
    return ($chk < 10 ? $chk : FALSE);
  }

  /** \brief - get next sequence from oracle or postgress
   * @param string $roll_sequence - name of number sequence
   * @param boolean $is_pg - is postgress sequence
   * @retval mixed - next number or FALSE
   */
  private function get_next_val($roll_sequence, $is_pg) {
    $this->watch->start('nextval');
    if ($is_pg) {
      $ret = self::pg_get_next_val($roll_sequence);
    }
    else {
      $ret = self::oci_get_next_val($roll_sequence);
    }
    $this->watch->stop('nextval');
    return $ret;
  }

  /** \brief - get next sequence from postgress
   * @param string $roll_sequence - name of number sequence
   * @retval mixed - next number or FALSE
   */
  private function pg_get_next_val($roll_sequence) {
    static $db;
    $ret = FALSE;
    if (empty($db)) {
      $db = self::get_pg_connection($this->config->get_value('pg_numberroll_credentials','setup') . ' connect_timeout=1');
    }
    if (is_object($db)) {
      try {
        $db->set_query('SELECT nextval(\'' . $roll_sequence . '\')');
        $db->execute();
        $val = $db->get_row();
        if (empty($val['nextval'])) {
          verbose::log(FATAL, 'OpenNumberRoll:: Got no number??');
        } else {
          $ret = $val['nextval'];
          verbose::log(TRACE, 'OpenNumberRoll:: ' . $roll_sequence . ' returned number: ' . $ret);
        }
      } catch (Exception $e) {
        verbose::log(FATAL, 'OpenNumberRoll:: PG select error: ' . $e->getMessage());
      }
    }
    return $ret;;
  }

  /** \brief - get next sequence from oracle
   * @param string $roll_sequence - name of number sequence
   * @retval mixed - next number or FALSE
   */
  private function oci_get_next_val($roll_sequence) {
    static $db;
    $ret = FALSE;
    if (empty($db)) {
      for ($i = 1; ($i <= OCI_CONNECT_LOOPS) && !is_object($db); $i++) {
        $db = self::get_oci_connection($this->config->get_value('numberroll_credentials','setup'), $i);
      }
    }
    if (is_object($db)) {
      try {
        $db->set_query('SELECT ' . $roll_sequence . '.nextval FROM dual');
        $val = $db->fetch_into_assoc();
        if (empty($val['NEXTVAL'])) {
          verbose::log(FATAL, 'OpenNumberRoll:: Got no number?? error: ' . $db->get_error_string());
        } else {
          $ret = $val['NEXTVAL'];
          verbose::log(TRACE, 'OpenNumberRoll:: ' . $roll_sequence . ' returned number: ' . $ret);
        }
      } catch (ociException $e) {
        verbose::log(FATAL, 'OpenNumberRoll:: OCI select error: ' . $db->get_error_string());
      }
    }
    return $ret;
  }

  /** \brief - creates a postgress connection
   * @param string $credentials -
   * @retval resource - 
   */
  private function get_pg_connection($credentials) {
    $pg = new Pg_database($credentials . ' connect_timeout=1');
    $this->watch->start('connect_pg');
    try {
      $pg->open();
      return $pg;
    } catch (Exection $e) {
      verbose::log(FATAL, 'OpenNumberRoll:: PG connect: ' . $e->getMessage());
    }
    return FALSE;
  }

  /** \brief - creates an oracle connection
   * @param string $credentials -
   * @retval resource - 
   */
  private function get_oci_connection($credentials, $attempt = 1) {
    $oci = new Oci($credentials);
    $oci->set_charset('UTF8');
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

//*
//* Local variables:
//* tab-width: 2
//* c-basic-offset: 2
//* End:
//* vim600: sw=2 ts=2 fdm=marker expandtab
//* vim<600: sw=2 ts=2 expandtab
//*/
