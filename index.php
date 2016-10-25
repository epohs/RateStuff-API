<?php
// SQLite/PDO tutorial
// https://phpdelusions.net/pdo

define('DEBUG', true);


if (DEBUG):
  ini_set("log_errors", 1);
  ini_set("error_log", "php-error.log");
endif;


class RateStuff {

  private $cfg = false;
  private $db = false;
  private $token = false;
  private $default_limit = 10; // Default items to return
  private $authorized_req = false;


  public function __construct() {

    header('Content-Type: application/json');

    set_exception_handler(array($this, 'exception_handler'));


    if ( !$this->config_exists() ):

      if ( !$this->write_default_config() ):

        $this->respond(array('status' => -666, 'msg' => 'Could not write config file.'));
        die();

      endif;

    endif;


    $this->cfg = require_once('config.php');


    if ( ($cfg_err = $this->is_config_setup()) !== true ):


      switch ($cfg_err):
        case -1:
          $this->respond(array('status' => -6, 'msg' => 'Config file does not exist.'));
          break;
        case -2:
          $this->respond(array('status' => -6, 'msg' => 'Malformed config file.'));
          break;
        case -3:
          $this->respond(array('status' => -6, 'msg' => 'Missing requirement in config.'));
          break;
        case -4:
          $this->respond(array('status' => -6, 'msg' => 'Config file needs setup.'));
          break;
        
        default:
          $this->respond(array('status' => -7, 'msg' => 'Config file generic error.'));
          break;
      endswitch;

      die();

    endif;


    $this->set_db_conn();


    $this->listen();


    // Close file db connection
    $this->db = null;

  } // __construct()






  /**
   * @since 0.0.1
   */
  private function listen() {

    $method = $_SERVER['REQUEST_METHOD'];

    if ( $this->is_valid_request() ):
      $parsed_uri = parse_url($_SERVER['REQUEST_URI']);

      //split the path into an array
      //we trim here to get rid of empty path ("")
      $request['path'] = explode('/', trim($parsed_uri['path'], '/'));
      parse_str($parsed_uri['query'], $request['query']);


      //$this->log( var_export($request, true) );

      switch ($method):
        case 'PUT':
          $this->handle_put($request);  
          break;
        case 'POST':
          $this->handle_post($request);  
          break;
        case 'GET':
          $this->handle_get($request);  
          break;
        case 'HEAD':
          $this->handle_head($request);  
          break;
        case 'DELETE':
          $this->handle_delete($request);  
          break;
        default:
          $this->respond(array('status' => -3, 'msg' => 'bad request type.'));
          break;
      endswitch;

    else:


      if ($method == 'OPTIONS'):

          $this->handle_options($request);   

      elseif($method == 'POST'):

        $posted_data = json_decode($_POST['credentials'], true);

        $login_try = ( isset($posted_data['user']) && isset($posted_data['pass']) );

        // The one case we will respond to a tokenless
        // request is when GETing a token.
        if ( $login_try ):

          $this->handle_post(array('path' => array('token', 'get')));

        else:

          $this->respond(array('status' => -2, 'msg' => 'login not good.'));

        endif;

      else:

        $this->respond(array('status' => -2, 'msg' => 'Bad call.'));

      endif;

    endif;

    

  } // listen()











  /**
   * 
   * @since 0.0.1
   * 
   * @return true
   */
  private function handle_put($request) {


    $_return = false;
    $target = ( isset($request['path'][0]) ) ? $request['path'][0] : false;

    switch ($target):
      
      case 'items':

        parse_str(file_get_contents("php://input"), $data);

        $json_data = json_decode($data['items'], true);

        if ( $json_data !== null ):

          //$this->log('RAW Data: ' . var_export($data, true));
          //$this->log('JSON Data: ' . var_export($json_data, true));

          $_return = $this->new_items($json_data);

        else:
          $this->respond(array('status' => -5, 'msg' => 'Invalid item data: ' . $target));
          return false;
        endif;

        break;
      
      default:
        $this->respond(array('status' => -4, 'msg' => 'Unhandled PUT target: ' . $target));
        return false;
        break;

    endswitch;

    $this->respond(array('status' => 1, $target => $_return));

  } // handle_put()





  /**
   * 
   * @since 0.0.1
   * 
   * @return true
   */
  private function handle_post($request) {

    $_return = false;
    $target = ( isset($request['path'][0]) ) ? $request['path'][0] : false;
    $action = ( isset($request['path'][1]) ) ? $request['path'][1] : false;



    switch ($target):
      
      case 'token':

        switch ($action):
          
          case 'get':

            $posted_data = $_POST['credentials'];

            $is_good_req = $this->chk_login( json_decode($posted_data, true) );

            if ($is_good_req):


              $token = ($t = $this->get_token()) ? $t : $this->set_token();


              if ( $token ):

                $this->respond(array('status' => 1, 'token' => $this->get_token()));

                return true;

              else:

                return false;

              endif;


            else:
              $this->respond(array('status' => -9, 'msg' => 'Bad token request.'));
              die();
            endif;

            break;
          
          default:

            $this->respond(array('status' => -8, 'msg' => 'Unhandled token POST request.'));
            die();

        endswitch;

        break;
      
      case 'items':
        switch ($action):
          
          case 'search':

            $posted_data = $_POST['search'];

            $search_data = json_decode($posted_data, true);

            if ($search_data):

              $found_items = $this->search_items($search_data);

              $this->respond(array('status' => null, 'msg' => $found_items));

            else:
              $this->respond(array('status' => -11, 'msg' => 'Bad search data.'));
              die();
            endif;

            break;
          
          default:

            $this->respond(array('status' => -10, 'msg' => 'Unhandled token POST request.'));
            die();

        endswitch;

        break;
      default:

        $this->respond(array('status' => -999, 'msg' => 'Unhandled POST.'));
        break;

    endswitch;



    

  } // handle_post()





  /**
   * 
   * @since 0.0.1
   * 
   * @return true
   */
  private function handle_get($request) {

    $_return = false;
    $target = ( isset($request['path'][0]) ) ? $request['path'][0] : false;
    $action = ( isset($request['path'][1]) ) ? $request['path'][1] : false;
    $args = ( isset($request['path'][2]) ) ? $request['path'][2] : false;

    switch ($target):
      
      case 'item':

        $id = (is_numeric($action)) ? array('id' => $action) : false;

        $_return = $this->get_items('single', $id);
        break;
      
      case 'items':

        $limit = (is_numeric($args)) ? array('limit' => $args) : false;
        $type = ($action) ? $action : 'newest';

        $_return = $this->get_items($type, $limit);
        break;
      
      case 'ratings':
        # code...
        break;
      
      case false:
        $target = 'logged-in';
        $_return = 'true';
        break;
      
      default:
        $this->respond(array('status' => -4, 'msg' => 'Unhandled target: ' . $target));
        die();

    endswitch;

    $this->respond(array('status' => 1, $target => $_return));

  } // handle_get()





  /**
   * 
   * @since 0.0.1
   * 
   * @return true
   */
  private function handle_head($request) {

    $this->respond(array('status' => -999, 'msg' => 'Unhandled HEAD.'));

  } // handle_head()





  /**
   * 
   * @since 0.0.1
   * 
   * @return true
   */
  private function handle_delete($request) {

    $this->respond(array('status' => -999, 'msg' => 'Unhandled DELETE.'));

  } // handle_delete()





  /**
   * 
   * @since 0.0.1
   * 
   * @return true
   */
  private function handle_options($request) {

    $this->respond(array('status' => -999, 'msg' => 'Unhandled OPTIONS.'));

    die();

  } // handle_options()






  /**
   * 
   * @since 0.0.1
   * 
   * @return true
   */
  private function respond($response) {

    echo json_encode($response);

    return true;

  } // respond()





  /**
   * 
   * @since 0.0.1
   * 
   * @return array
   */
  private function get_items($action, $args = array()) {

    $limit = ( isset($args['limit']) ) ? $args['limit'] : $this->default_limit;
    $item_id = ( isset($args['id']) ) ? $args['id'] : false;

    switch ($action):

      case 'single':

        if ( !is_numeric($item_id) ) { return array(); }

        $sql = 'SELECT i.*, (CAST(r.rating AS REAL) / 10) AS rating, GROUP_CONCAT(t.tag) AS tags
                FROM items AS i
                LEFT JOIN item_ratings AS ir
                  ON ir.item_id = i.id
                LEFT JOIN ratings AS r
                  ON r.id = ir.rating_id
                  AND r.is_primary = 1
                LEFT JOIN item_tags AS it
                  ON it.item_id = i.id
                LEFT JOIN tags AS t
                  ON t.id = it.tag_id 
                WHERE i.id = :id
                GROUP BY i.id';

        $sql_args = array(':id' => $item_id);
        break;

      case 'newest':

        $sql = 'SELECT i.*, (CAST(r.rating AS REAL) / 10) AS rating, GROUP_CONCAT(t.tag) AS tags
                FROM items AS i
                LEFT JOIN item_ratings AS p
                  ON p.item_id = i.id
                LEFT JOIN ratings AS r
                  ON r.id = p.rating_id
                  AND r.is_primary = 1
                LEFT JOIN item_tags AS it
                  ON it.item_id = i.id
                LEFT JOIN tags AS t
                  ON t.id = it.tag_id
                GROUP BY i.id
                ORDER BY i.created DESC
                LIMIT :limit';

        $sql_args = array(':limit' => $limit);
        break;
      
      default:

        return array();
        break;

    endswitch;


    $stmt = $this->db->prepare($sql);

    $stmt->execute($sql_args);


    return $stmt->fetchAll(PDO::FETCH_ASSOC);

  } // get_items()








  /**
   * 
   * @since 0.0.1
   * 
   * @param array $item_data
   * 
   * @return array
   */
  private function search_items($search_data, $limit = false, $offset = 0) {


    if ( !is_array($search_data) ) { return -1; }

    if ( !array_key_exists('query', $search_data) ) { return -2; }

    $limit = ( is_int($limit) ) ? $limit : $this->default_limit;

    $query_str = trim($search_data['query']);

    $query_words = explode(' ', $query_str);

    $wheres = array();
    $arguments = array();

    // Searching for more than 5 words would
    // get nuts.
    $query_words = array_slice($query_words, 0, 5); 

    foreach ($query_words as $word):

      $title_wheres[] = "`title` LIKE ?";
      $msg_wheres[] = "`message` LIKE ?";

      $arguments[] = '%'.$word.'%';
      $arguments[] = '%'.$word.'%';

    endforeach;




    $sql = "WITH all_data AS (
              SELECT *, 1 AS r FROM `items` WHERE ";
    
    $sql .= implode(' OR ', $title_wheres);

    $sql .= ' UNION ALL ';
    $sql .= ' SELECT *, 0 AS r FROM `items` WHERE ' . implode(' OR ', $msg_wheres);
    $sql .= ' AND `id` NOT IN (
                SELECT id FROM `items` WHERE ' . implode(' OR ', $title_wheres);
    $sql .= '  )
             )
            SELECT `id`, `title`, `message`, `created`, `modified` 
            FROM all_data ORDER BY r DESC';
    $sql .= ' LIMIT ' . $offset . ', ' . $limit;

    $this->log('$sql: ' . var_export($sql, true));
    $this->log('$arguments: ' . var_export($arguments, true));


    $stmt = $this->db->prepare($sql);

    $stmt->execute($arguments);

    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $this->log('$result: ' . var_export($result, true));


    return $result;

  } // search_items()













  /**
   * 
   * @since 0.0.1
   * 
   * @param array $item_data
   * 
   * @return array
   */
  private function new_items($item_data) {

    if ( is_array($item_data) ):

      if ( isset($item_data['items']) ):

        $items = $item_data['items'];

        if ( !empty($items) ):

          $_return = array();
          $invalid_items = array();
          $new_items = array();
          $invalid_tags = array();
          $new_tags = array();

          foreach ($items as $item):

            $title = ( isset($item['title']) ) ? trim($item['title']) : false;
            $message = ( isset($item['message']) ) ? trim($item['message']) : null;
            $rating = ( isset($item['rating']) ) ? $item['rating'] : false;
            $tags = ( isset($item['tags']) ) ? $item['tags'] : false;

            $rating = ( is_numeric($rating) ) ? number_format($rating, 1) : false; // second check on rating
            $tags = ( is_array($tags) ) ? array_filter(array_map('trim', $tags)) : false; // second check on tags



            if ( !$title || !$rating ):
              array_push($invalid_items, $item);
              continue;
            endif;

            // Begin a transaction.
            // All insert statements that happen
            // before we commit() will be rolled
            // back if any of them fail.
            $this->db->beginTransaction();

            $item_id = $this->insert_item($title, $message);
            
            // If the insert failed, rollback the
            // insert and skip the rest of the FOR loop.
            if ( !$item_id ):
              array_push($invalid_items, $item);
              $this->db->rollback();
              continue;
            endif;


            // Insert the rating and grab the ID.
            // Note: We don't support adding messages
            // to primary ratings, so the message param
            // is left null.
            $rating_id = $this->insert_rating($item_id, $rating, null);

            // If the inserting the rating failed, rollback 
            // the insert of both the item and the rating
            // and skip the rest of the FOR loop.
            if ( !$rating_id ):
              $this->log('insert of rating failed. ' . var_export($rating_id, true));
              array_push($invalid_items, $item);
              $this->db->rollback();
              continue;
            endif;



            // If we have made it to this point in the 
            // loop, then we can assume the item and 
            // rating were both valid, so add the item
            // ID to our array and commit the changes.
            array_push($new_items, $item_id);
            $this->db->commit();



            // Tags are the lowest on the priority list
            // and are not required, so we will insert
            // them after we have already committed the
            // item and ratings data.
            if ( !empty($tags) ):

              foreach ($tags as $tag):

                // We only support tags as strings.
                if ( !is_string($tag) ):
                  array_push($invalid_tags, $tag);
                  continue;
                endif;
                
                $tag_id = $this->add_tag($tag, $item_id);

              endforeach;

            endif;

          endforeach;



          if( !empty($invalid_items) ):

            $_return['invalid_items'] = $invalid_items;

          endif;


          if( !empty($invalid_tags) ):

            $_return['invalid_tags'] = $invalid_tags;

          endif;


          if( empty($new_items) ):

            // If there were no items added to the
            // database then simply return false
            return false;

          else:

            $_return['new_items'] = $new_items;

          endif;



          return $_return;

        else:

          return false;

        endif;

      else:

        return false;

      endif;

    else:

      return false;

    endif;

  } // new_items()




  /**
   * 
   * @since 0.0.1
   * 
   * @return int|false
   */
  function insert_item($title = false, $msg = null) {

    if ( !$title ) { return false; }

    try {

      $sql = "INSERT INTO items (title, message) VALUES(:title, :msg)";
      $stmt = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

      $stmt->execute(array(':title' => $title, ':msg' => $msg));

    } catch ( PDOException $e ) {

      $this->log($e->getMessage());
      return false;

    }

    return $this->db->lastInsertId();

  } // insert_item()





  /**
   * 
   * @since 0.0.1
   * 
   * @param int $item_id Required.
   * @param int $rating Required.
   * @param string $msg
   * 
   * @return int|false
   */
  function insert_rating($item_id = false, $rating = false, $msg = null) {

    if ( !$item_id || !$rating ) { return false; }

    $rating = (is_numeric($rating)) ? ($rating * 10) : null;

    // Try inserting the rating and the relationship
    // between the rating and its parent item.
    // Both inserts are required to succeed, else we
    // return false.
    try {

      $sql = "INSERT INTO ratings (rating, message) VALUES(:rating, :msg)";
      $stmt = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

      $stmt->execute(array(':rating' => $rating, ':msg' => $msg));

      $rating_id = $this->db->lastInsertId();

      // If inserting the rating succeeded then we also
      // need to create the relationship between the rating
      // and its item.
      if ( $rating_id ):
        $sql = "INSERT INTO item_ratings (item_id, rating_id) VALUES(:item, :rating)";
        $stmt = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

        $stmt->execute(array(':item' => $item_id, ':rating' => $rating_id));
      else:
        
        // Failure creating the relationship
        return false;

      endif;

    } catch ( PDOException $e ) {

      $this->log($e->getMessage());
      return false;

    }

    return $rating_id;

  } // insert_rating()





  /**
   * 
   * @since 0.0.1
   * 
   * @return int|false
   */
  private function add_tag($tag = false, $item_id = false, $message = false) {

    if ( !$tag || !$item_id ) { return false; }

    $existing_tag = $this->get_tag($tag, $item_id);

    $tag_id = false;

    if ( empty($existing_tag) ):

      // This tag din't exist, so INSERT it now
      // and grab that ID.
      $tag_id = $this->insert_tag($tag, $item_id, $message);

    else:

      // Use the existing tag's ID, if available.
      $tag_id = ( isset($existing_tag['id']) ) ? $existing_tag['id'] : false;

    endif;

    
    return $this->attach_tag($tag_id, $item_id);

    
  } // add_tag()






  /**
   * 
   * @since 0.0.1
   * 
   * @return int|false
   */
  private function insert_tag($tag = false, $message = false) {

    if ( !$tag ) { return false; }

    try {

      $sql = "INSERT INTO tags (tag, message) VALUES(:tag, :msg)";
      $stmt = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

      $stmt->execute(array(':tag' => $tag, ':msg' => $msg));

      $tag_id = $this->db->lastInsertId();

      return $tag_id;

    } catch ( PDOException $e ) {

      $this->log($e->getMessage());
      return false;

    }


  } // insert_tag()






  /**
   * 
   * @since 0.0.1
   * 
   * @return int|false
   */
  private function attach_tag($tag_id = false, $item_id = false) {

    if ( !$tag_id || !$item_id ) { return false; }

    try {

      $sql = "INSERT INTO item_tags (item_id, tag_id) VALUES(:item, :tag)";
      $stmt = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

      $stmt->execute(array(':item' => $item_id, ':tag' => $tag_id));


    } catch ( PDOException $e ) {

      $this->log($e->getMessage());
      return false;

    }

  } // attach_tag()







  /**
   * 
   * @since 0.0.1
   * 
   * @return int|false
   */
  private function get_tag($tag = false, $item_id = false) {

    if ( !$tag ) { return false; }

    $comp = ( is_int($tag) ) ? "id =" : "tag LIKE";

    try {

      $sql = "SELECT * FROM tags WHERE {$comp} ?";
      $stmt = $this->db->prepare($sql);
      $stmt->execute(array($tag));

      return $stmt->fetch(PDO::FETCH_ASSOC);

    } catch ( PDOException $e ) {

      $this->log($e->getMessage());
      return false;

    }

  } // get_tag()





  /**
   * Verifies whether a request contained a valid token
   * Sets the global property accordingly.
   * 
   * @since 0.0.1
   * 
   * @return bool
   */
  private function is_valid_request() {

    $token = $_SERVER['HTTP_RATE_STUFF_TOKEN'];

    $this->authorized_req = ($token === $this->get_token());

    return $this->authorized_req;

  } // is_valid_request()





  /**
   *
   * @return bool
   */
  private function chk_login( $json_creds = false ) {

    $credentials = $json_creds;

    if ( $credentials !== null ):

      //$this->log('credentials received: ' . var_export($credentials, true));

      if ( isset($credentials['user']) && isset($credentials['pass']) ):

        if ( $credentials['user'] === $this->cfg['user'] &&
              $credentials['pass'] === $this->cfg['pass'] ):

          return $this->authorized_req = true;

        endif;

      endif;

    endif;

    return $this->authorized_req = false;

  }




  /**
   * 
   * @since 0.0.1
   * 
   * @return bool
   */
  private function get_token( $force_db_check = false ) {

    if ( !$force_db_check ):

      if ( $this->token ):

        $this->log('token from cache: ' . var_export($this->token, true));

        return $this->token;

      endif;

    endif;

    $stmt = $this->db->prepare("SELECT token
                                FROM credentials
                                WHERE modified <= date('now','30 days')
                                LIMIT 0, 1");
    $stmt->execute();
    
    $token = $stmt->fetchColumn();

    $this->log('token from db: ' . var_export($token, true));


    return $this->token = $token;

  } // is_valid_request()





  /**
   * 
   * @since 0.0.1
   * 
   * @return bool
   */
  private function set_token() {

    $this->log('setting token.');

    if ( !$this->authorized_req ) { return false; }

    $this->log('passed auth check.');    


    try {

      $existing_token = $this->get_token();

      if ( $existing_token === false ):

        $sql = 'INSERT INTO `credentials` (`token`) VALUES (:token)';

      else:

        $sql = 'UPDATE `credentials` SET `token` = :token';

      endif;

      $this->log('Executing: ' . "(" . var_export($existing_token, true) . ") " . $sql); 

      $stmt = $this->db->prepare($sql);

      $new_token = md5($this->cfg['user'] . $this->cfg['pass'] . $this->cfg['salt']);

      $stmt->bindParam(':token', $new_token, PDO::PARAM_STR);

      $stmt->execute();

      if ( $stmt->rowCount() ):

        return $this->get_token();

      else:

        $this->log('token update had no effect on the database.');

        return false;

      endif;

    } catch(PDOException $e) {

      $this->log('token update failed. ' . $e->getMessage());

      return false;

    }
    

  } // is_valid_request()








  /**
   * Create or connect to the database and
   * set the class parameter for re-use.
   * 
   * @since 0.0.1
   */
  private function set_db_conn() {

    try {
   
      // Create (connect to) SQLite database in file
      $file_db = new PDO('sqlite:' . $this->cfg['db_file']);

      // Prevent emulated prepares
      $file_db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

      // Set errormode to exceptions
      $file_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

      $file_db->exec('PRAGMA foreign_keys = ON;');


      if ( !$this->is_db_ready($file_db) ) {

        $this->make_tables($file_db);

      }


      // Set the global database connection property
      $this->db = $file_db;

      $file_db = null;

   
    } catch(PDOException $e) {

      // Print PDOException message
      $this->respond( array('status' => -1,
                            'msg' => $e->getMessage()) );

    }



  } // set_db_conn()









  /**
   * 
   * @since 0.0.1
   * 
   */
  private function is_db_ready($db) {

    // Try a select statement against the table
    // Run it in try/catch in case PDO is in ERRMODE_EXCEPTION.
    try {

      $result = $db->query("SELECT 1 FROM 'items' LIMIT 1");

      // Result is either boolean FALSE (no table found) or PDOStatement Object (table found)
      return $result !== false;

    } catch (Exception $e) {
      // We got an exception == table not found
      return false;
    }


  } // is_db_ready()






  /**
   * 
   * @since 0.0.1
   * 
   */
  private function make_tables($db) {

    try {

      // Create items table
      $db->exec("CREATE TABLE IF NOT EXISTS items (
                              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                              title VARCHAR(45) NOT NULL,
                              message TEXT,
                              created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                              modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL);");

      // Create ratings table
      $db->exec("CREATE TABLE IF NOT EXISTS ratings (
                  id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                  rating INTEGER DEFAULT 0 NOT NULL CHECK(rating >= 0 AND rating <= 50),
                  message TEXT,
                  is_primary INTEGER DEFAULT 0 NOT NULL,
                  created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                  modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL);");
      // Create items table
      $db->exec("CREATE TABLE IF NOT EXISTS tags (
                              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                              tag VARCHAR(15) NOT NULL,
                              message TEXT,
                              created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                              modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL);");

      // Create the items-to-ratings pivot table
      $db->exec("CREATE TABLE IF NOT EXISTS item_ratings (
                  `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                  `item_id` INTEGER,
                  `rating_id` INTEGER UNIQUE,
                  `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  FOREIGN KEY(`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE,
                  FOREIGN KEY(`rating_id`) REFERENCES `ratings`(`id`) ON DELETE CASCADE);");

      $db->exec("CREATE INDEX rating_item_id_index ON item_ratings(item_id);");
      $db->exec("CREATE INDEX rating_rating_id_index ON item_ratings(rating_id);");

      // Create the items-to-ratings pivot table
      $db->exec("CREATE TABLE IF NOT EXISTS item_tags (
                  `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                  `item_id` INTEGER,
                  `tag_id` INTEGER,
                  `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  FOREIGN KEY(`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE,
                  FOREIGN KEY(`tag_id`) REFERENCES `tags`(`id`) ON DELETE CASCADE);");

      $db->exec("CREATE INDEX tag_item_id_index ON item_tags(item_id);");
      $db->exec("CREATE INDEX tag_rating_id_index ON item_tags(tag_id);");




      // Create credentials table
      $db->exec("CREATE TABLE IF NOT EXISTS credentials (
                              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                              token VARCHAR(45) NOT NULL,
                              created DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                              modified DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL);");


      $db->exec("CREATE TRIGGER insert_item_title_max AFTER INSERT ON items
                  WHEN (LENGTH(NEW.title) > 45)
                  BEGIN
                    UPDATE items SET title = substr(NEW.title, 1, 45) WHERE id = NEW.id;
                  END;");

      $db->exec("CREATE TRIGGER update_item_item_title_max AFTER UPDATE OF title ON items
                  WHEN (LENGTH(NEW.title) > 45)
                  BEGIN
                    UPDATE items SET title = substr(NEW.title, 1, 45) WHERE id = NEW.id;
                  END;");

      /* // Causes problems in the IDE but is a good idea for the production site
      $db->exec("CREATE TRIGGER insert_item_title_min AFTER INSERT ON items
                  WHEN (LENGTH(NEW.title) <= 0)
                  BEGIN
                    SELECT RAISE (ABORT, 'Title too short.');
                  END;");
      */

      $db->exec("CREATE TRIGGER update_item_item_title_min AFTER UPDATE OF title ON items
                  WHEN (LENGTH(NEW.title) <= 0)
                  BEGIN
                    SELECT RAISE (ABORT, 'Title too short.');
                  END;");

      $db->exec("CREATE TRIGGER modified_item AFTER UPDATE ON items
                  BEGIN
                    UPDATE items SET modified = CURRENT_TIMESTAMP WHERE id = NEW.id;
                  END;");

      $db->exec("CREATE TRIGGER modified_rating AFTER UPDATE ON ratings
                  BEGIN
                    UPDATE items SET modified = CURRENT_TIMESTAMP WHERE id = NEW.id;
                  END;");

      $db->exec("CREATE TRIGGER modified_tag AFTER UPDATE ON tags
                  BEGIN
                    UPDATE tags SET modified = CURRENT_TIMESTAMP WHERE id = NEW.id;
                  END;");

      $db->exec("CREATE TRIGGER modified_credentials AFTER UPDATE ON credentials
                  BEGIN
                    UPDATE credentials SET modified = CURRENT_TIMESTAMP WHERE id = NEW.id;
                  END;");


      $db->exec("CREATE TRIGGER insert_tag_len_max AFTER INSERT ON tags
                  WHEN (LENGTH(NEW.tag) > 15)
                  BEGIN
                    UPDATE tags SET tag = substr(NEW.tag, 1, 15) WHERE id = NEW.id;
                  END;");


      $db->exec("CREATE TRIGGER insert_tag_strip_chars AFTER INSERT ON tags
                  WHEN (INSTR(NEW.tag, ',') > 0)
                  BEGIN
                    UPDATE tags SET tag = REPLACE(tag, ',', '') WHERE id = NEW.id;
                  END;");

      /* // Causes problems in the IDE but is a good idea for the production site
      $db->exec("CREATE TRIGGER insert_tag_len_min AFTER INSERT ON tags
                  WHEN (LENGTH(NEW.tag) <= 0)
                  BEGIN
                    SELECT RAISE (ABORT, 'Tag too short.');
                  END;");
      */

      $db->exec("CREATE TRIGGER update_tag_len_max AFTER UPDATE OF tag ON tags
                  WHEN (LENGTH(NEW.tag) > 15)
                  BEGIN
                    UPDATE tags SET tag = substr(NEW.tag, 1, 15) WHERE id = NEW.id;
                  END;");

      $db->exec("CREATE TRIGGER update_tag_len_min AFTER UPDATE OF tag ON tags
                  WHEN (LENGTH(NEW.tag) <= 0)
                  BEGIN
                    SELECT RAISE (ABORT, 'Tag too short.');
                  END;");

      $db->exec("CREATE TRIGGER update_tag_strip_chars AFTER UPDATE OF tag ON tags
                  WHEN (INSTR(NEW.tag, ',') > 0)
                  BEGIN
                    UPDATE tags SET tag = REPLACE(tag, ',', '') WHERE id = NEW.id;
                  END;");
                  
      
      // When we create a new rating relationship we
      // need to check to verify whether there is a
      // primary rating. If not, make this one it.
      $db->exec("CREATE TRIGGER insert_rating_rel_req_primary AFTER INSERT ON item_ratings
                  WHEN ( (NEW.item_id IS NOT NULL) AND
                         (NEW.rating_id IS NOT NULL) AND
                         ((SELECT COUNT(i2r.id)
                            FROM item_ratings AS i2r
                            WHERE i2r.item_id = NEW.item_id) <= 1) )
                  BEGIN
                    -- Set this rating to primary
                    UPDATE ratings SET is_primary = 1 WHERE id = NEW.rating_id;
                  
                  END;");

      // When we modify a rating relationship we
      // need to check to verify whether there is a
      // primary rating. If not, make this one it.
      $db->exec("CREATE TRIGGER modify_rating_rel_req_primary AFTER UPDATE ON item_ratings
                  WHEN ( (NEW.item_id IS NOT NULL) AND
                         (NEW.rating_id IS NOT NULL) AND
                         ((SELECT COUNT(i2r.id)
                            FROM item_ratings AS i2r
                            WHERE i2r.item_id = NEW.item_id) <= 1) )
                  BEGIN
                    -- Set this rating to primary
                    UPDATE ratings SET is_primary = 1 WHERE id = NEW.rating_id;
                  
                  END;");

      
      $db->exec("CREATE TRIGGER modified_rating_multi_primary AFTER UPDATE OF is_primary ON ratings

                  -- Check to see if any other ratings for
                  -- this item are also marked as primary
                  WHEN ( NEW.is_primary = 1 
                        AND ((SELECT COUNT(r.id) AS c
                          FROM ratings AS r
                          LEFT JOIN item_ratings AS p
                            ON p.rating_id = r.id
                          LEFT JOIN items AS i
                            ON i.id = p.item_id
                          WHERE i.id IN (
                            SELECT i2.id
                            FROM items AS i2
                            LEFT JOIN item_ratings AS p2
                              ON p2.item_id = i2.id
                            LEFT JOIN ratings AS r2
                              ON r2.id = p2.rating_id
                            WHERE r2.id = NEW.id)
                          AND r.is_primary = 1) > 0) )
                  BEGIN

                    -- Update all other ratings assigned to the
                    -- item that we just modified to UNSET them
                    -- as the primary rating.
                    UPDATE ratings 
                    SET is_primary = 0 
                    WHERE id IN (
                                SELECT r.id AS c
                                FROM ratings AS r
                                LEFT JOIN item_ratings AS p
                                  ON p.rating_id = r.id
                                LEFT JOIN items AS i
                                  ON i.id = p.item_id
                                WHERE i.id IN (
                                  SELECT i2.id
                                  FROM items AS i2
                                  LEFT JOIN item_ratings AS p2
                                    ON p2.item_id = i2.id
                                  LEFT JOIN ratings AS r2
                                    ON r2.id = p2.rating_id
                                  WHERE r2.id = NEW.id)
                                AND r.is_primary = 1
                                AND r.id != NEW.id );
                  END;");


      $db->exec("CREATE TRIGGER modified_rating_req_primary AFTER UPDATE OF is_primary ON ratings

                  -- Check to see if any other ratings for
                  -- this item are also marked as primary.
                  -- If they are not then ABORT this change.
                  WHEN ( NEW.is_primary = 0 
                        AND ((SELECT COUNT(r.id) AS c
                          FROM ratings AS r
                          LEFT JOIN item_ratings AS p
                            ON p.rating_id = r.id
                          LEFT JOIN items AS i
                            ON i.id = p.item_id
                          WHERE i.id IN (
                            SELECT i2.id
                            FROM items AS i2
                            LEFT JOIN item_ratings AS p2
                              ON p2.item_id = i2.id
                            LEFT JOIN ratings AS r2
                              ON r2.id = p2.rating_id
                            WHERE r2.id = NEW.id)
                          AND r.is_primary = 1) <= 0) )
                  BEGIN

                    SELECT RAISE (ABORT, 'At least 1 rating must be primary.');

                  END;");
                  

      $this->log('tables did not exist. making them now.');

      return true;

    } catch (Exception $e) {

      $this->log('Trouble. ' . $e->getMessage());

      // We got an exception == table not found
      return false;
    }

  } // make_tables()









  private function config_exists() {

    return file_exists('config.php');

  } // config_exists()








  /**
   * 
   * @since 0.0.1
   * 
   * @return bool
   */
  private function is_config_setup() {

    if ( !$this->config_exists() ) { return -1; }

    if ( !is_array($this->cfg) ) { return -2; }

    if ( !isset($this->cfg['user']) ||
         !isset($this->cfg['pass']) ||
         !isset($this->cfg['salt']) ) { return -3; }

    $default_config = $this->get_default_config();

    if ( ($this->cfg['user'] === $default_config['user']) ||
         ($this->cfg['pass'] === $default_config['pass']) ) { return -4; }


    return true;

  } // is_config_setup()





  private function get_default_config() {

    return [
      'ABS_PATH' => dirname(__FILE__),
      'db_file' => 'storage.db',
      'user' => 'USERNAME',
      'pass' => 'PASSWORD',
      'salt' => md5(uniqid(rand(), true))
    ];

  } // get_default_config()







  private function write_default_config() {

    $fh = fopen('config.php', "w");

    if (!is_resource($fh)) { return false; }


    $default_config = $this->get_default_config();

    fwrite($fh, "<?php\n\n");
    
    fwrite($fh, "return [\n");

    foreach ($default_config as $key => $value):

      fwrite($fh, sprintf("'%s' => '%s',\n", $key, $value));

    endforeach;

    fwrite($fh, "];\n\n");

    fwrite($fh, "?>\n");

    fclose($fh);

    return true;

  } // write_default_config()







  /**
   * Write data to a log file in the current directory.
   * 
   * @since 0.0.1
   * 
   * @param mixed $log_data What you want to write to the log.
   * 
   * @return bool Always true.
   */
  public static function log( $log_data=null ) {

    $activity_log_path = dirname(__FILE__) . "/debug.log";

    $activity_log = $log_data . "\n";

    error_log($activity_log, 3, $activity_log_path);

    return true;

  } // log()






  /**
   * @since 0.0.1
   */
  public function exception_handler($e) {
    
    // Print generic message
    $error_array = array(
                        'status' => 0,
                        'msg' => $e->getMessage() 
                        );

    $this->respond($error_array);

  }



} // RateStuff


$rate_stuff = new RateStuff;












?>