<?php

  /*
   * Copyright 2011-2012 Evernote Corporation.
   *
   * This file contains functions used by Evernote's PHP OAuth samples.
   */

  // Include the Evernote API from the lib subdirectory. 
  // lib simply contains the contents of /php/lib from the Evernote API SDK
  define("EVERNOTE_LIBS", dirname(__FILE__) . DIRECTORY_SEPARATOR . "lib");
  ini_set("include_path", ini_get("include_path") . PATH_SEPARATOR . EVERNOTE_LIBS);

  require_once("Thrift.php");
  require_once("transport/TTransport.php");
  require_once("transport/THttpClient.php");
  require_once("protocol/TProtocol.php");
  require_once("protocol/TBinaryProtocol.php");
  require_once("packages/Types/Types_types.php");
  require_once("packages/UserStore/UserStore.php");
  require_once("packages/NoteStore/NoteStore.php");
  require_once("packages/Limits/Limits_constants.php");

  // Import the classes that we're going to be using
  use EDAM\NoteStore\NoteStoreClient, EDAM\NoteStore\NoteFilter;
  use EDAM\Types\Notebook, EDAM\Types\NoteSortOrder, EDAM\Types\Note;
  use EDAM\Error\EDAMSystemException, EDAM\Error\EDAMUserException, EDAM\Error\EDAMErrorCode;

  // Verify that you successfully installed the PHP OAuth Extension
  if (!class_exists('OAuth')) {
    die("<span style=\"color:red\">The PHP OAuth Extension is not installed</span>");
  }

  // Verify that you have configured your API key
  if (strlen(OAUTH_CONSUMER_KEY) == 0 || strlen(OAUTH_CONSUMER_SECRET) == 0) {
    $configFile = dirname(__FILE__) . '/config.php';
    die("<span style=\"color:red\">Before using this sample code you must edit the file $configFile " .
        "and fill in OAUTH_CONSUMER_KEY and OAUTH_CONSUMER_SECRET with the values that you received from Evernote. " .
        "If you do not have an API key, you can request one from " .
        "<a href=\"http://dev.evernote.com/documentation/cloud/\">http://dev.evernote.com/documentation/cloud/</a></span>");
  }

  /*
   * The first step of OAuth authentication: the client (this application) 
   * obtains temporary credentials from the server (Evernote). 
   *
   * After successfully completing this step, the client has obtained the
   * temporary credentials identifier, an opaque string that is only meaningful 
   * to the server, and the temporary credentials secret, which is used in 
   * signing the token credentials request in step 3.
   *
   * This step is defined in RFC 5849 section 2.1:
   * http://tools.ietf.org/html/rfc5849#section-2.1
   *
   * @return boolean TRUE on success, FALSE on failure
   */
  function getTemporaryCredentials($script_name = null) {
    global $lastError, $currentStatus;
    try {
      $oauth = new OAuth(OAUTH_CONSUMER_KEY, OAUTH_CONSUMER_SECRET);
      $requestTokenInfo = $oauth->getRequestToken(REQUEST_TOKEN_URL, getCallbackUrl($script_name));
      if ($requestTokenInfo) {
        $_SESSION['requestToken'] = $requestTokenInfo['oauth_token'];
        $_SESSION['requestTokenSecret'] = $requestTokenInfo['oauth_token_secret'];
        $currentStatus = 'Obtained temporary credentials';
        return TRUE;
      } else {
        $lastError = 'Failed to obtain temporary credentials: ' . $oauth->getLastResponse();
      }
    } catch (OAuthException $e) {
      $lastError = 'Error obtaining temporary credentials: ' . $e->getMessage();
    }
    return false;
  }

  /*
   * The completion of the second step in OAuth authentication: the resource owner 
   * authorizes access to their account and the server (Evernote) redirects them 
   * back to the client (this application).
   * 
   * After successfully completing this step, the client has obtained the
   * verification code that is passed to the server in step 3.
   *
   * This step is defined in RFC 5849 section 2.2:
   * http://tools.ietf.org/html/rfc5849#section-2.2
   *
   * @return boolean TRUE if the user authorized access, FALSE if they declined access.
   */
  function handleCallback() {
    global $lastError, $currentStatus;
    if (isset($_GET['oauth_verifier'])) {
      $_SESSION['oauthVerifier'] = $_GET['oauth_verifier'];
      $currentStatus = 'Content owner authorized the temporary credentials';
      return TRUE;
    } else {
      // If the User clicks "decline" instead of "authorize", no verification code is sent
      $lastError = 'Content owner did not authorize the temporary credentials';
      return FALSE;
    }
  }

  /*
   * The third and final step in OAuth authentication: the client (this application)
   * exchanges the authorized temporary credentials for token credentials.
   *
   * After successfully completing this step, the client has obtained the
   * token credentials that are used to authenticate to the Evernote API.
   * In this sample application, we simply store these credentials in the user's
   * session. A real application would typically persist them.
   *
   * This step is defined in RFC 5849 section 2.3:
   * http://tools.ietf.org/html/rfc5849#section-2.3
   *
   * @return boolean TRUE on success, FALSE on failure
   */
  function getTokenCredentials() {
    global $lastError, $currentStatus;
    
    if (isset($_SESSION['accessToken'])) {
      $lastError = 'Temporary credentials may only be exchanged for token credentials once';
      return FALSE;
    }
    
    try {
      $oauth = new OAuth(OAUTH_CONSUMER_KEY, OAUTH_CONSUMER_SECRET);
      $oauth->setToken($_SESSION['requestToken'], $_SESSION['requestTokenSecret']);
      $accessTokenInfo = $oauth->getAccessToken(ACCESS_TOKEN_URL, null, $_SESSION['oauthVerifier']);
      if ($accessTokenInfo) {
        $_SESSION['accessToken'] = $accessTokenInfo['oauth_token'];
        $_SESSION['accessTokenSecret'] = $accessTokenInfo['oauth_token_secret'];
        $_SESSION['noteStoreUrl'] = $accessTokenInfo['edam_noteStoreUrl'];
        $_SESSION['webApiUrlPrefix'] = $accessTokenInfo['edam_webApiUrlPrefix'];
        // The expiration date is sent as a Java timestamp - milliseconds since the Unix epoch
        $_SESSION['tokenExpires'] = (int)($accessTokenInfo['edam_expires'] / 1000);
        $_SESSION['userId'] = $accessTokenInfo['edam_userId'];
        $currentStatus = 'Exchanged the authorized temporary credentials for token credentials';
        return TRUE;
      } else {
        $lastError = 'Failed to obtain token credentials: ' . $oauth->getLastResponse();
      }
    } catch (OAuthException $e) {
      $lastError = 'Error obtaining token credentials: ' . $e->getMessage();
    }  
    //trigger_error($lastError);
    return FALSE;
  }
  
  /*
   * Demonstrate the use of token credentials obtained via OAuth by listing the notebooks
   * in the resource owner's Evernote account using the Evernote API. Returns an array
   * of String notebook names.
   *
   * Once you have obtained the token credentials identifier via OAuth, you can use it
   * as the auth token in any call to an Evernote API function.
   *
   * @return boolean TRUE on success, FALSE on failure
   */
  function listNotebooks() {
    global $lastError, $currentStatus;
    
    try {
  		$parts = parse_url($_SESSION['noteStoreUrl']);
      if (!isset($parts['port'])) {
        if ($parts['scheme'] === 'https') {
          $parts['port'] = 443;
        } else {
          $parts['port'] = 80;
        }
      }

      $noteStoreTrans = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);

      $noteStoreProt = new TBinaryProtocol($noteStoreTrans);
      $noteStore = new NoteStoreClient($noteStoreProt, $noteStoreProt);
      
      $authToken = $_SESSION['accessToken'];
      $notebooks = $noteStore->listNotebooks($authToken);
      $result = array();
      if (!empty($notebooks)) {
        foreach ($notebooks as $notebook) {
          $result[] = $notebook->name;
        }
      }
      $_SESSION['notebooks'] = $result;
      $currentStatus = 'Successfully listed content owner\'s notebooks';
      return $notebooks;
    } catch (EDAMSystemException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error listing notebooks: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error listing notebooks: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMUserException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error listing notebooks: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error listing notebooks: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMNotFoundException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error listing notebooks: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error listing notebooks: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (Exception $e) {
      $lastError = 'Error listing notebooks: ' . $e->getMessage();
    }
    //trigger_error($lastError);
    return FALSE;
  }
  
  function createNotebookByNameAndStack($name, $stack = null) {
    global $lastError, $currentStatus;

    try {
  		$parts = parse_url($_SESSION['noteStoreUrl']);
      if (!isset($parts['port'])) {
        if ($parts['scheme'] === 'https') {
          $parts['port'] = 443;
        } else {
          $parts['port'] = 80;
        }
      }

      $noteStoreTrans = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);

      $noteStoreProt = new TBinaryProtocol($noteStoreTrans);
      $noteStore = new NoteStoreClient($noteStoreProt, $noteStoreProt);

      if (!is_null($stack)) {
        $notebook = new Notebook(compact("name", "stack"));
      } else {
        $notebook = new Notebook(compact("name"));
      }

      $authToken = $_SESSION['accessToken'];
      $notebook = $noteStore->createNotebook($authToken, $notebook);
      $currentStatus = 'Successfully created a new notebook';
      return $notebook;
    } catch (EDAMSystemException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error creating notebook: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error creating notebook: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMUserException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error creating notebook: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error creating notebook: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMNotFoundException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error creating notebook: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error creating notebook: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (Exception $e) {
      $lastError = 'Error creating notebook: ' . $e->getMessage();
    }
    trigger_error($lastError);
    return FALSE;
  }

  function findNotesByNotebookGuidOrderedByCreated($notebookGuid, $offset, $maxNotes) {
    global $lastError, $currentStatus;

    try {
  		$parts = parse_url($_SESSION['noteStoreUrl']);
      if (!isset($parts['port'])) {
        if ($parts['scheme'] === 'https') {
          $parts['port'] = 443;
        } else {
          $parts['port'] = 80;
        }
      }

      $noteStoreTrans = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);

      $noteStoreProt = new TBinaryProtocol($noteStoreTrans);
      $noteStore = new NoteStoreClient($noteStoreProt, $noteStoreProt);

      $order = NoteSortOrder::CREATED;
      $ascending = TRUE;
      $filter = new NoteFilter(compact("notebookGuid","order","ascending"));

      $authToken = $_SESSION['accessToken'];
      $notes = $noteStore->findNotes($authToken, $filter, $offset, $maxNotes);
      $currentStatus = 'Successfully found requested notes';
      return $notes;
    } catch (EDAMSystemException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error finding notes: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error finding notes: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMUserException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error finding notes: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error finding notes: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMNotFoundException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error finding notes: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error finding notes: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (Exception $e) {
      $lastError = 'Error finding notes: ' . $e->getMessage();
    }
    trigger_error($lastError);
    return FALSE;
  }

  function moveNote($note, $toNotebookGuid) {
    global $lastError, $currentStatus;

    try {
  		$parts = parse_url($_SESSION['noteStoreUrl']);
      if (!isset($parts['port'])) {
        if ($parts['scheme'] === 'https') {
          $parts['port'] = 443;
        } else {
          $parts['port'] = 80;
        }
      }

      $noteStoreTrans = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);

      $noteStoreProt = new TBinaryProtocol($noteStoreTrans);
      $noteStore = new NoteStoreClient($noteStoreProt, $noteStoreProt);

      $note->notebookGuid = $toNotebookGuid;

      $authToken = $_SESSION['accessToken'];
      $result = $noteStore->updateNote($authToken, $note);
      $currentStatus = 'Successfully moved note';
      return $result;
    } catch (EDAMSystemException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error moving note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error moving note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMUserException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error moving note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error moving note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMNotFoundException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error moving note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error moving note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (Exception $e) {
      $lastError = 'Error moving note: ' . $e->getMessage();
    }
    trigger_error($lastError);
    return FALSE;
  }

  function updateNote($note) {
    global $lastError, $currentStatus;

    try {
  		$parts = parse_url($_SESSION['noteStoreUrl']);
      if (!isset($parts['port'])) {
        if ($parts['scheme'] === 'https') {
          $parts['port'] = 443;
        } else {
          $parts['port'] = 80;
        }
      }

      $noteStoreTrans = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);

      $noteStoreProt = new TBinaryProtocol($noteStoreTrans);
      $noteStore = new NoteStoreClient($noteStoreProt, $noteStoreProt);

      $authToken = $_SESSION['accessToken'];
      $result = $noteStore->updateNote($authToken, $note);
      $currentStatus = 'Successfully updated note';
      return $result;
    } catch (EDAMSystemException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error updating note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error updating note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMUserException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error updating note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error updating note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMNotFoundException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error updating note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error updating note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (Exception $e) {
      $lastError = 'Error updating note: ' . $e->getMessage();
    }
    trigger_error($lastError);
    return FALSE;
  }

  function getNote($guid, $withContent = false, $withResourcesData = false, $withResourcesRecognition = false, $withResourcesAlternateData = false) {
    global $lastError, $currentStatus;

    try {
  		$parts = parse_url($_SESSION['noteStoreUrl']);
      if (!isset($parts['port'])) {
        if ($parts['scheme'] === 'https') {
          $parts['port'] = 443;
        } else {
          $parts['port'] = 80;
        }
      }

      $noteStoreTrans = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);

      $noteStoreProt = new TBinaryProtocol($noteStoreTrans);
      $noteStore = new NoteStoreClient($noteStoreProt, $noteStoreProt);

      $authToken = $_SESSION['accessToken'];
      $result = $noteStore->getNote($authToken, $guid, $withContent, $withResourcesData, $withResourcesRecognition, $withResourcesAlternateData);
      $currentStatus = 'Successfully retrieved note';
      return $result;
    } catch (EDAMSystemException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error retrieving note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error retrieving note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMUserException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error retrieving note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error retrieving note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMNotFoundException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error retrieving note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error retrieving note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (Exception $e) {
      $lastError = 'Error retrieving note: ' . $e->getMessage();
    }
    trigger_error($lastError);
    return FALSE;
  }

  function getSharedNoteUrl($guid) {
    global $lastError, $currentStatus;

    try {
  		$parts = parse_url($_SESSION['noteStoreUrl']);
      if (!isset($parts['port'])) {
        if ($parts['scheme'] === 'https') {
          $parts['port'] = 443;
        } else {
          $parts['port'] = 80;
        }
      }

      $noteStoreTrans = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);

      $noteStoreProt = new TBinaryProtocol($noteStoreTrans);
      $noteStore = new NoteStoreClient($noteStoreProt, $noteStoreProt);

      $authToken = $_SESSION['accessToken'];
      $shareKey = $noteStore->shareNote($authToken, $guid);

      $path_parts = explode('/', $parts['path']);

      $shardId = $path_parts[2];

      $url = "https://www.evernote.com/shard/" . $shardId . "/sh/" . $guid . "/" . $shareKey;

      $currentStatus = 'Successfully retrieved shared note url';
      return $url;
    } catch (EDAMSystemException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error retrieving shared note url: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error retrieving shared note url: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMUserException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error retrieving shared note url: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error retrieving shared note url: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMNotFoundException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error retrieving shared note url: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error retrieving shared note url: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (Exception $e) {
      $lastError = 'Error retrieving shared note url: ' . $e->getMessage();
    }
    trigger_error($lastError);
    return FALSE;
  }

  /**
   * Create a note consisting of a title and plain text content (transformed into basic ENML)
   *
   * @global type $lastError
   * @global string $currentStatus
   * @param type $title
   * @param type $content Contents in plain text
   * @param type $notebookGuid
   * @return boolean
   * @throws Exception
   */
  function createSimpleNote($title, $content, $notebookGuid = null) {
    global $lastError, $currentStatus;

    try {
  		$parts = parse_url($_SESSION['noteStoreUrl']);
      if (!isset($parts['port'])) {
        if ($parts['scheme'] === 'https') {
          $parts['port'] = 443;
        } else {
          $parts['port'] = 80;
        }
      }

      $noteStoreTrans = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);

      $noteStoreProt = new TBinaryProtocol($noteStoreTrans);
      $noteStore = new NoteStoreClient($noteStoreProt, $noteStoreProt);

      $note = new Note();
      $note->title = $title;

      // When note titles are user-generated, it's important to validate them
      $len = strlen($note->title);
      $min = $GLOBALS['EDAM_Limits_Limits_CONSTANTS']['EDAM_NOTE_TITLE_LEN_MIN'];
      $max = $GLOBALS['EDAM_Limits_Limits_CONSTANTS']['EDAM_NOTE_TITLE_LEN_MAX'];
      $pattern = '#' . $GLOBALS['EDAM_Limits_Limits_CONSTANTS']['EDAM_NOTE_TITLE_REGEX'] . '#'; // Add PCRE delimiters
      if ($len < $min || $len > $max || !preg_match($pattern, $note->title)) {
	throw new Exception("Invalid note title: " . $note->title);
      }

      // The content of an Evernote note is represented using Evernote Markup Language
      // (ENML). The full ENML specification can be found in the Evernote API Overview
      // at http://dev.evernote.com/documentation/cloud/chapters/ENML.php
      $note->content =
        '<?xml version="1.0" encoding="UTF-8"?>' .
        '<!DOCTYPE en-note SYSTEM "http://xml.evernote.com/pub/enml2.dtd">' .
        '<en-note>' .
	  nl2br($content) .
        '</en-note>';

      // If notebookGuid is not supplied, the default notebook will be used
      if (!is_null($notebookGuid)) {
        $note->notebookGuid = $notebookGuid;
      }

      $authToken = $_SESSION['accessToken'];

      // Finally, send the new note to Evernote using the createNote method
      // The new Note object that is returned will contain server-generated
      // attributes such as the new note's unique GUID.
      $createdNote = $noteStore->createNote($authToken, $note);

      $currentStatus = 'Successfully created new note with GUID: ' . $createdNote->guid;
      return $createdNote;
    } catch (EDAMSystemException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error creating new note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error creating new note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMUserException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error creating new note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error creating new note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (EDAMNotFoundException $e) {
      if (isset(EDAMErrorCode::$__names[$e->errorCode])) {
        $lastError = 'Error creating new note: ' . EDAMErrorCode::$__names[$e->errorCode] . ": " . $e->parameter;
      } else {
        $lastError = 'Error creating new note: ' . $e->getCode() . ": " . $e->getMessage();
      }
    } catch (Exception $e) {
      $lastError = 'Error creating new note: ' . $e->getMessage();
    }
    trigger_error($lastError);
    return FALSE;
  }

  /*
   * Reset the current session.
   */
  function resetSession() {
    if (isset($_SESSION['requestToken'])) {
      unset($_SESSION['requestToken']);
    }
    if (isset($_SESSION['requestTokenSecret'])) {
      unset($_SESSION['requestTokenSecret']);
    }
    if (isset($_SESSION['oauthVerifier'])) {
      unset($_SESSION['oauthVerifier']);
    }
    if (isset($_SESSION['accessToken'])) {
      unset($_SESSION['accessToken']);
    }
    if (isset($_SESSION['accessTokenSecret'])) {
      unset($_SESSION['accessTokenSecret']);
    }
    if (isset($_SESSION['noteStoreUrl'])) {
      unset($_SESSION['noteStoreUrl']);
    }
    if (isset($_SESSION['webApiUrlPrefix'])) {
      unset($_SESSION['webApiUrlPrefix']);
    }
    if (isset($_SESSION['tokenExpires'])) {
    	unset($_SESSION['tokenExpires']);
    }
    if (isset($_SESSION['userId'])) {
    	unset($_SESSION['userId']);
    }
    if (isset($_SESSION['notebooks'])) {
      unset($_SESSION['notebooks']);
    }
  }
  
  /*
   * Get the URL of this application. This URL is passed to the server (Evernote)
   * while obtaining unauthorized temporary credentials (step 1). The resource owner 
   * is redirected to this URL after authorizing the temporary credentials (step 2).
   */
  function getCallbackUrl($script_name = null) {
    $thisUrl = (empty($_SERVER['HTTPS'])) ? "http://" : "https://";
    $thisUrl .= $_SERVER['HTTP_HOST'];
    $thisUrl .= ($_SERVER['SERVER_PORT'] == 80 || $_SERVER['SERVER_PORT'] == 443) ? "" : (":".$_SERVER['SERVER_PORT']);
    $thisUrl .= !is_null($script_name) ? $script_name : $_SERVER['SCRIPT_NAME'];
    $thisUrl .= '?action=callback';
    return $thisUrl;
  }
  
  /*
   * Get the Evernote server URL used to authorize unauthorized temporary credentials.
   */
  function getAuthorizationUrl() {
    $url = AUTHORIZATION_URL;
    $url .= '?oauth_token=';
    $url .= urlencode($_SESSION['requestToken']);
    return $url;
  }  
?>
