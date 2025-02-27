<?php
/*
 *  Copyright (c) 2010-2014 Tinyboard Development Group
 */
require "./inc/functions.php";
require "./inc/anti-bot.php";

// Fix for magic quotes
if (get_magic_quotes_gpc()) {
	function strip_array($var) {
		return is_array($var) ? array_map('strip_array', $var) : stripslashes($var);
	}
	
	$_GET = strip_array($_GET);
	$_POST = strip_array($_POST);
}

if (isset($_POST['delete'])) {
	// Delete
	
	if (!isset($_POST['board'], $_POST['password']))
		error($config['error']['bot']);
	
	$password = &$_POST['password'];
	
	if ($password == '')
		error($config['error']['cantemptypassword']);
	
	$delete = array();
	foreach ($_POST as $post => $value) {
		if (preg_match('/^delete_(\d+)$/', $post, $m)) {
			$delete[] = (int)$m[1];
		}
	}
	
	if (checkDNSBL()) error("Tor users may not delete posts.");
		
	// Check if board exists
	if (!openBoard($_POST['board']))
		error($config['error']['noboard']);
	
	// Check if banned
	checkBan($board['uri']);

	// Check if deletion enabled
	if (!$config['allow_delete'])
		error(_('Users are not allowed to delete their own posts on this board.'));
	
	if (empty($delete))
		error($config['error']['nodelete']);
		
	foreach ($delete as &$id) {
		$query = prepare(sprintf("SELECT `thread`, `time`,`password` FROM ``posts_%s`` WHERE `id` = :id", $board['uri']));
		$query->bindValue(':id', $id, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		
		if ($post = $query->fetch(PDO::FETCH_ASSOC)) {
			$thread = false;
			if ($config['user_moderation'] && $post['thread']) {
				$thread_query = prepare(sprintf("SELECT `time`,`password` FROM ``posts_%s`` WHERE `id` = :id", $board['uri']));
				$thread_query->bindValue(':id', $post['thread'], PDO::PARAM_INT);
				$thread_query->execute() or error(db_error($query));

				$thread = $thread_query->fetch(PDO::FETCH_ASSOC);	
			}

			if ($password != '' && $post['password'] !== $password && (!$thread || $thread['password'] !== $password))
				error($config['error']['invalidpasswordtryrefresh']);
					
			
			if ($post['time'] > time() - $config['delete_time'] && (!$thread || $thread['password'] != $password)) {
				error(sprintf($config['error']['delete_too_soon'], until($post['time'] + $config['delete_time'])));
			}
			
			if (isset($_POST['file'])) {
				// Delete just the file
				deleteFile($id);
				modLog("User deleted file from his own post #$id");
			} else {
				// Delete entire post
				deletePost($id);
				modLog("User deleted his own post #$id");
			}
			
			_syslog(LOG_INFO, 'Deleted post: ' .
				'/' . $board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $post['thread'] ? $post['thread'] : $id) . ($post['thread'] ? '#' . $id : '')
			);
		}
	}
	
	buildIndex();

	$is_mod = isset($_POST['mod']) && $_POST['mod'];
	$root = $is_mod ? $config['root'] . $config['file_mod'] . '?/' : $config['root'];

	if (!isset($_POST['json_response'])) {
		header('Location: ' . $root . $board['dir'] . $config['file_index'], true, $config['redirect_http']);
	} else {
		header('Content-Type: text/json');
		echo json_encode(array('success' => true));
	}

        // We are already done, let's continue our heavy-lifting work in the background (if we run off FastCGI)
        if (function_exists('fastcgi_finish_request'))
                @fastcgi_finish_request();

	rebuildThemes('post-delete', $board['uri']);

} elseif (isset($_POST['report'])) {
	if (!isset($_POST['board'], $_POST['reason']))
		error($config['error']['bot']);
	
	$report = array();
	foreach ($_POST as $post => $value) {
		if (preg_match('/^delete_(\d+)$/', $post, $m)) {
			$report[] = (int)$m[1];
		}
	}
	
	//if (checkDNSBL()) error("Tor users may not report posts.");
		
	// Check if board exists
	if (!openBoard($_POST['board']))
		error($config['error']['noboard']);
	
	// Check if banned
	checkBan($board['uri']);
	
	if(strlen($_POST['reason']) > 50)
		error($config['error']['report_limit_50']);
	
	if (empty($report))
		error($config['error']['noreport']);
	
	if (count($report) > $config['report_limit'])
		error($config['error']['toomanyreports']);

	if ($config['report_captcha'] && !isset($_POST['captcha_text'], $_POST['captcha_cookie'])) {
		error($config['error']['bot']);
	}

	if ($config['report_captcha']) {
		$resp = file_get_contents($config['captcha']['provider_check'] . "?" . http_build_query([
			'mode' => 'check',
			'text' => $_POST['captcha_text'],
			'extra' => $config['captcha']['extra'],
			'cookie' => $_POST['captcha_cookie']
		]));

		if ($resp !== '1') {
			$error = $config['error']['captcha'];
		}
	}
	
	if (isset($error)) {
		if ($config['report_captcha']) {
			$captcha = generate_captcha($config['captcha']['extra']);
		} else {
			$captcha = null;
		}

		$body = Element('report.html', array('board' => $board, 'config' => $config, 'error' => $error, 'reason_prefill' => $_POST['reason'], 'post' => 'delete_'.$report[0], 'captcha' => $captcha, 'global' => isset($_POST['global'])));
		echo Element('page.html', ['config' => $config, 'body' => $body]);
		die();
	}
	
	$reason = escape_markup_modifiers($_POST['reason']);
	markup($reason);
	
	foreach ($report as &$id) {
		$query = prepare(
			"SELECT
				`thread`,
				`post_clean`.`clean_local`,
				`post_clean`.`clean_global`
			FROM `posts_{$board['uri']}`
			LEFT JOIN `post_clean`
				ON `post_clean`.`board_id` = '{$board['uri']}'
				AND `post_clean`.`post_id` = :id
			WHERE `id` = :id"
		);
		$query->bindValue(':id', $id, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		
		if( $post = $query->fetch(PDO::FETCH_ASSOC) ) {
			$report_local  = !$post['clean_local'];
			$report_global = isset($_POST['global']) && !$post['clean_global'];
			
			if( $report_local || $report_global ) {
				$thread = $post['thread'];
				
				if ($config['syslog']) {
					_syslog(LOG_INFO, 'Reported post: ' .
						'/' . $board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $thread ? $thread : $id) . ($thread ? '#' . $id : '') .
						' for "' . $reason . '"'
					);
				}
				$identity = getIdentity();
				
				$query = prepare("INSERT INTO `reports` (`time`, `ip`, `board`, `post`, `reason`, `local`, `global`) VALUES (:time, :ip, :board, :post, :reason, :local, :global)");
				$query->bindValue(':time',   time(), PDO::PARAM_INT);
				$query->bindValue(':ip',     $identity, PDO::PARAM_STR);
				$query->bindValue(':board',  $board['uri'], PDO::PARAM_INT);
				$query->bindValue(':post',   $id, PDO::PARAM_INT);
				$query->bindValue(':reason', $reason, PDO::PARAM_STR);
				$query->bindValue(':local',  $report_local, PDO::PARAM_BOOL);
				$query->bindValue(':global', $report_global, PDO::PARAM_BOOL);
				$query->execute() or error(db_error($query));
			}
		}
	}
	
	$is_mod = isset($_POST['mod']) && $_POST['mod'];
	$root = $is_mod ? $config['root'] . $config['file_mod'] . '?/' : $config['root'];
	
	if (!isset($_POST['json_response'])) {
		$index = $root . $board['dir'] . $config['file_index'];
		echo Element('page.html', array('config' => $config, 'body' => '<div style="text-align:center"><a href="javascript:window.close()">[ ' . _('Close window') ." ]</a> <a href='$index'>[ " . _('Return') . ' ]</a></div>', 'title' => _('Report submitted!')));
	} else {
		header('Content-Type: text/json');
		echo json_encode(array('success' => true));
	}
}
elseif (isset($_POST['post'])) {
	if (!isset($_POST['body'], $_POST['board'])) {
		error($config['error']['bot']);
	}
	
	$post = array(
		'board' => $_POST['board'],
		'files' => array(),
		'time'  => time(), // Timezone independent UNIX timecode.
	);
	
	// Check if board exists
	if (!openBoard($post['board']))
		error($config['error']['noboard']);

	
	if (!isset($_POST['name']))
		$_POST['name'] = $config['anonymous'];
	
	if (!isset($_POST['email']))
		$_POST['email'] = '';
	
	if (!isset($_POST['subject']))
		$_POST['subject'] = '';
	
	if (!isset($_POST['password']))
		$_POST['password'] = '';
	
	if (isset($_POST['thread'])) {
		$post['op'] = false;
		$post['thread'] = round($_POST['thread']);
	} else
		$post['op'] = true;

	// The dnsbls is an optional DNS blacklist include.
	// Squelch warnings if it doesn't exist.
	if (!$config['captcha']['enabled'] && !($post['op'] && $config['new_thread_capt'])) {
		@include "./inc/dnsbls.php";
	}

	// Check if banned
	checkBan($board['uri']);

	if(isset($_POST['password']) && $_POST['password']=="")
		error($config['error']['passwordrequired']);
	
	// Check for CAPTCHA right after opening the board so the "return" link is in there
	//if ($config['captcha']['enabled']) {
	//New thread captcha
	if (($config['captcha']['enabled']) || (($post['op']) && ($config['new_thread_capt'])) ) {
		$resp = file_get_contents($config['captcha']['provider_check'] . "?" . http_build_query([
			'mode' => 'check',
			'text' => $_POST['captcha_text'],
			'extra' => $config['captcha']['extra'],
			'cookie' => $_POST['captcha_cookie']
		]));

		if ($resp !== '1') {
                        error($config['error']['captcha'] .
			'<script>if (actually_load_captcha !== undefined) actually_load_captcha("'.$config['captcha']['provider_get'].'", "'.$config['captcha']['extra'].'");</script>');
		}
	}

	//if (!(($post['op'] && $_POST['post'] == $config['button_newtopic']) ||
		//(!$post['op'] && $_POST['post'] == $config['button_reply'])))
		//error($config['error']['bot']);
	
	// Check the referrer
	if ($config['referer_match'] !== false &&
		(!isset($_SERVER['HTTP_REFERER']) || !preg_match($config['referer_match'], rawurldecode($_SERVER['HTTP_REFERER'])))) {
		error($config['error']['referer']);
	}	

	if ($post['mod'] = isset($_POST['mod']) && $_POST['mod']) {
		check_login(false);
		if (!$mod) {
			// Liar. You're not a mod.
			error($config['error']['notamod']);
		}
		
		$post['sticky'] = $post['op'] && isset($_POST['sticky']);
		$post['locked'] = $post['op'] && isset($_POST['lock']);
		$post['raw'] = isset($_POST['raw']);
		
		if ($post['sticky'] && !hasPermission($config['mod']['sticky'], $board['uri']))
			error($config['error']['noaccess']);
		if ($post['locked'] && !hasPermission($config['mod']['lock'], $board['uri']))
			error($config['error']['noaccess']);
		if ($post['raw'] && !hasPermission($config['mod']['rawhtml'], $board['uri']))
			error($config['error']['noaccess']);
	}
	
	if (!$post['mod']) {
		$post['antispam_hash'] = checkSpam(array($board['uri'], isset($post['thread']) ? $post['thread'] : ($config['try_smarter'] && isset($_POST['page']) ? 0 - (int)$_POST['page'] : null)));
		if ($post['antispam_hash'] === true && $config['enable_antibot'])
			error($config['error']['spam']);
	}
	
	if ($config['robot_enable'] && $config['robot_mute']) {
		checkMute();
	}
	
        /*Check Force Anonymous thread*/
        if(isset($config['force_anon_thread'])){
                if (isset($_POST['force_anon']) && $post['op']) {
                        $post['force_anon'] = true;
                } else{
                        $post['force_anon'] = false;

                        if (isset($_POST['thread'])) {
                                //Check if force_anon is enable or 1, then forced anonymous, to avoid field form injection.
                                $query = prepare(sprintf("SELECT `force_anon` FROM ``posts_%s`` WHERE `id` = :id", $board['uri']));
                                $query->bindValue(':id', $post['thread'], PDO::PARAM_INT);
                                $query->execute() or error(db_error());
                                if ($row = $query->fetch()) {
                                        if($row['force_anon']==1){
                                                $_POST['name'] = $config['anonymous']; // forced anonymous
                                        }
                                }
                        }

                }
        }
	
	//Check if thread exists
	if (!$post['op']) {
		$query = prepare(sprintf("SELECT `sticky`,`locked`,`cycle`,`sage` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board['uri']));
		$query->bindValue(':id', $post['thread'], PDO::PARAM_INT);
		$query->execute() or error(db_error());
		
		if (!$thread = $query->fetch(PDO::FETCH_ASSOC)) {
			// Non-existant
			error($config['error']['nonexistant']);
		}
	}
		
	
	// Check for an embed field
	if ($config['enable_embedding'] && isset($_POST['embed']) && !empty($_POST['embed'])) {
		// yep; validate it
		$value = $_POST['embed'];
		foreach ($config['embedding'] as &$embed) {
			if (preg_match($embed[0], $value)) {
				// Valid link
				$post['embed'] = $value;
				// This is bad, lol.
				$post['no_longer_require_an_image_for_op'] = true;
				break;
			}
		}
		if (!isset($post['embed'])) {
			error($config['error']['invalid_embed']);
		}

		if ($config['image_reject_repost']) {
			if ($p = getPostByEmbed($post['embed'])) {
				error(sprintf($config['error']['fileexists'], 
					($post['mod'] ? $config['root'] . $config['file_mod'] . '?/' : $config['root']) .
					($board['dir'] . $config['dir']['res'] .
						($p['thread'] ?
							$p['thread'] . '.html#' . $p['id']
						:
							$p['id'] . '.html'
						))
				));
			}
		} else if (!$post['op'] && $config['image_reject_repost_in_thread']) {
			if ($p = getPostByEmbedInThread($post['embed'], $post['thread'])) {
				error(sprintf($config['error']['fileexistsinthread'], 
					($post['mod'] ? $config['root'] . $config['file_mod'] . '?/' : $config['root']) .
					($board['dir'] . $config['dir']['res'] .
						($p['thread'] ?
							$p['thread'] . '.html#' . $p['id']
						:
							$p['id'] . '.html'
						))
				));
			}
		}
	}
	
	if (!hasPermission($config['mod']['bypass_field_disable'], $board['uri'])) {
		if ($config['field_disable_name'])
			$_POST['name'] = $config['anonymous']; // "forced anonymous"
	
		if ($config['field_disable_email'])
			$_POST['email'] = '';

		if ($config['field_email_selectbox'] && $_POST['email'] != 'sage')
			$_POST['email'] = '';
	
		if ($config['field_disable_password'])
			$_POST['password'] = '';
	
		if ($config['field_disable_subject'] || (!$post['op'] && $config['field_disable_reply_subject']))
			$_POST['subject'] = '';
	}
	
	if ($config['allow_upload_by_url'] && isset($_POST['file_url']) && !empty($_POST['file_url'])) {
		$post['file_url'] = $_POST['file_url'];
		if (!preg_match('@^https?://@', $post['file_url']))
			error($config['error']['invalidimg']);
		
		if (mb_strpos($post['file_url'], '?') !== false)
			$url_without_params = mb_substr($post['file_url'], 0, mb_strpos($post['file_url'], '?'));
		else
			$url_without_params = $post['file_url'];

		$post['extension'] = strtolower(mb_substr($url_without_params, mb_strrpos($url_without_params, '.') + 1));

		if ($post['op'] && $config['allowed_ext_op']) {
			if (!in_array($post['extension'], $config['allowed_ext_op']))
				error($config['error']['unknownext']);
		}
		else if (!in_array($post['extension'], $config['allowed_ext']) && !in_array($post['extension'], $config['allowed_ext_files']))
			error($config['error']['unknownext']);

		$post['file_tmp'] = tempnam($config['tmp'], 'url');
		function unlink_tmp_file($file) {
			@unlink($file);
			fatal_error_handler();
		}
		register_shutdown_function('unlink_tmp_file', $post['file_tmp']);
		
		$fp = fopen($post['file_tmp'], 'w');
		
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $post['file_url']);
		curl_setopt($curl, CURLOPT_FAILONERROR, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($curl, CURLOPT_TIMEOUT, $config['upload_by_url_timeout']);
		curl_setopt($curl, CURLOPT_USERAGENT, 'Tinyboard');
		curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($curl, CURLOPT_FILE, $fp);
		curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
		
		if (curl_exec($curl) === false)
			error($config['error']['nomove'] . '<br/>Curl says: ' . curl_error($curl));
		
		curl_close($curl);
		
		fclose($fp);

		$_FILES['file'] = array(
			'name' => basename($url_without_params),
			'tmp_name' => $post['file_tmp'],
			'file_tmp' => true,
			'error' => 0,
			'size' => filesize($post['file_tmp'])
		);
	}
	
	$post['name'] = $_POST['name'] != '' ? $_POST['name'] : $config['anonymous'];
	$post['subject'] = $_POST['subject'];
	$post['email'] = str_replace(' ', '%20', htmlspecialchars($_POST['email']));

	if (isset($_POST['no-bump'])) {
		if (!empty($post['email'])) {
			$post['email'] .= '+sage';
		} else {
			$post['email'] = 'sage';
		}
	}
		
	$post['body'] = $_POST['body'];
	$post['password'] = $_POST['password'];
	$post['has_file'] = (!isset($post['embed']) && (($post['op'] && !isset($post['no_longer_require_an_image_for_op']) && $config['force_image_op']) || !empty($_FILES['file']['name'])));

	// Handle our Tor users
	$tor = checkDNSBL();
	if ($tor && !(isset($_SERVER['HTTP_X_TOR'], $_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] == '127.0.0.2' && $_SERVER['HTTP_X_TOR'] = 'true'))
		error('To post on 8chan over Tor, you must use the hidden service for security reasons. You can find it at <a href="http://fullchan4jtta4sx.onion">http://fullchan4jtta4sx.onion</a>.');
	if ($tor && $post['has_file'] && !$config['tor_image_posting'])
		error('Sorry. Tor users can\'t upload files on this board.');
	if ($tor && !$config['tor_posting'])
		error('Sorry. The owner of this board has decided not to allow Tor posters for some reason...');

	if ($post['has_file'] && $config['disable_images']) {
		error($config['error']['images_disabled']);
	}
	
	if (!($post['has_file'] || isset($post['embed'])) || (($post['op'] && $config['force_body_op']) || (!$post['op'] && $config['force_body']))) {
		// http://stackoverflow.com/a/4167053
		$stripped_whitespace = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $post['body']);
		if ($stripped_whitespace == '') {
			error($config['error']['tooshort_body']);
		}
	}

	if ($config['force_subject_op'] && $post['op']) {
		$stripped_whitespace = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $post['subject']);
		if ($stripped_whitespace == '') {
			error(_('It is required to enter a subject when starting a new thread on this board.'));
		}
	}
	
	if (!$post['op']) {
		// Check if thread is locked
		// but allow mods to post
		if ($thread['locked'] && !hasPermission($config['mod']['postinlocked'], $board['uri']))
			error($config['error']['locked']);
		
		$numposts = numPosts($post['thread']);
		
		if ($config['reply_hard_limit'] != 0 && $config['reply_hard_limit'] <= $numposts['replies'])
			error($config['error']['reply_hard_limit']);
		
		if ($post['has_file'] && $config['image_hard_limit'] != 0 && $config['image_hard_limit'] <= $numposts['images'])
			error($config['error']['image_hard_limit']);
	}
		
	if ($post['has_file']) {
		// Determine size sanity
		$size = 0;
		if ($config['multiimage_method'] == 'split') {
			foreach ($_FILES as $key => $file) {
				$size += $file['size'];
			}
		} elseif ($config['multiimage_method'] == 'each') {
			foreach ($_FILES as $key => $file) {
				if ($file['size'] > $size) {
					$size = $file['size'];
				}
			}
		} else {
			error(_('Unrecognized file size determination method.'));
		}

		if ($size > $config['max_filesize'])
			error(sprintf3($config['error']['filesize'], array(
				'sz' => number_format($size),
				'filesz' => number_format($size),
				'maxsz' => number_format($config['max_filesize'])
			)));
		$post['filesize'] = $size;
	}
	
	
	$post['capcode'] = false;
	
	if ($mod && preg_match('/^((.+) )?## (.+)$/', $post['name'], $matches) && (in_array($board['uri'], $mod['boards']) or $mod['boards'][0] == '*')) {
		$name = $matches[2] != '' ? $matches[2] : $config['anonymous'];
		$cap = $matches[3];
		
		if (isset($config['mod']['capcode'][$mod['type']])) {
			if (	$config['mod']['capcode'][$mod['type']] === true ||
				(is_array($config['mod']['capcode'][$mod['type']]) &&
					in_array($cap, $config['mod']['capcode'][$mod['type']])
				)) {
				
				$post['capcode'] = utf8tohtml($cap);
				$post['name'] = $name;
			}
		}
	}
	
	$trip = generate_tripcode($post['name']);
	$post['name'] = $trip[0];
	$post['trip'] = isset($trip[1]) ? $trip[1] : '';
	
	$noko = false;
	if (strtolower($post['email']) == 'noko') {
		$noko = true;
		$post['email'] = '';
	} elseif (strtolower($post['email']) == 'nonoko'){
		$noko = false;
		$post['email'] = '';
	} else $noko = $config['always_noko'];
	
	if ($post['has_file']) {
		$i = 0;
		foreach ($_FILES as $key => $file) {
			if ($file['size'] && $file['tmp_name']) {
				$file['filename'] = urldecode(get_magic_quotes_gpc() ? stripslashes($file['name']) : $file['name']);
				$file['extension'] = strtolower(mb_substr($file['filename'], mb_strrpos($file['filename'], '.') + 1));
				if (isset($config['filename_func']))
					$file['file_id'] = $config['filename_func']($file);
				else
					$file['file_id'] = time() . substr(microtime(), 2, 3);

				if (sizeof($_FILES) > 1)
					$file['file_id'] .= "-$i";
				
				$file['file'] = $config['dir']['img_root'] . $board['dir'] . $config['dir']['img'] . $file['file_id'] . '.' . $file['extension'];

				while (file_exists ($file['file'])) {
					$file['file_id'] .= rand(0,9);
					$file['file'] = $config['dir']['img_root'] . $board['dir'] . $config['dir']['img'] . $file['file_id'] . '.' . $file['extension'];
				}

				$file['thumb'] = $config['dir']['img_root'] . $board['dir'] . $config['dir']['thumb'] . $file['file_id'] . '.' . ($config['thumb_ext'] ? $config['thumb_ext'] : $file['extension']);
				$post['files'][] = $file;
				$i++;
			}
		}
	}

	if (empty($post['files'])) $post['has_file'] = false;

	// Check for a file
	if ($post['op'] && !isset($post['no_longer_require_an_image_for_op'])) {
		if (!$post['has_file'] && $config['force_image_op'])
			error($config['error']['noimage']);
	}

	// Check for too many files
	if (sizeof($post['files']) > $config['max_images'])
		error($config['error']['toomanyimages']);

	if ($config['strip_combining_chars']) {
		$post['name'] = strip_combining_chars($post['name']);
		$post['email'] = strip_combining_chars($post['email']);
		$post['subject'] = strip_combining_chars($post['subject']);
		$post['body'] = strip_combining_chars($post['body']);
	}
	
	// Check string lengths
	if (mb_strlen($post['name']) > 35)
		error(sprintf($config['error']['toolong'], 'name'));	
	if (mb_strlen($post['email']) > 40)
		error(sprintf($config['error']['toolong'], 'email'));
	if (mb_strlen($post['subject']) > 100)
		error(sprintf($config['error']['toolong'], 'subject'));
	if (!$mod && mb_strlen($post['body']) > $config['max_body'])
		error($config['error']['toolong_body']);
	if (mb_strlen($post['body']) < $config['min_body'] && $post['op'])
		error(sprintf(_('OP must be at least %d chars on this board.'), $config['min_body']));
	if (mb_strlen($post['password']) > 20)
		error(sprintf($config['error']['toolong'], 'password'));
		
	wordfilters($post['body']);

	if ($config['max_newlines'] > 0) {
		preg_match_all("/\n/", $post['body'], $nlmatches);
	
		if (isset($nlmatches[0]) && sizeof($nlmatches[0]) > $config['max_newlines'])
			error(sprintf(_('Your post contains too many lines. This board only allows %d maximum.'), $config['max_newlines']));
	}
	
	
	$post['body'] = escape_markup_modifiers($post['body']);
	
	if ($mod && isset($post['raw']) && $post['raw']) {
		$post['body'] .= "\n<tinyboard raw html>1</tinyboard>";
	}
	
	if (($config['country_flags'] && (!$config['allow_no_country'] || $config['force_flag'])) || ($config['country_flags'] && $config['allow_no_country'] && !isset($_POST['no_country']))) {
		require 'inc/lib/geoip/geoip.inc';
		$gi=geoip\geoip_open('inc/lib/geoip/GeoIPv6.dat', GEOIP_STANDARD);
	
		function ipv4to6($ip) {
			if (strpos($ip, ':') !== false) {
				if (strpos($ip, '.') > 0)
					$ip = substr($ip, strrpos($ip, ':')+1);
				else return $ip;  //native ipv6
			}
			$iparr = array_pad(explode('.', $ip), 4, 0);
			$part7 = base_convert(($iparr[0] * 256) + $iparr[1], 10, 16);
			$part8 = base_convert(($iparr[2] * 256) + $iparr[3], 10, 16);
			return '::ffff:'.$part7.':'.$part8;
		}
	
		$country_code = geoip\geoip_country_code_by_addr_v6($gi, ipv4to6($_SERVER['REMOTE_ADDR']));
		$country_name = geoip\geoip_country_name_by_addr_v6($gi, ipv4to6($_SERVER['REMOTE_ADDR']));
		if (!$country_code) $country_code = 'A1';
		if (!$country_name) $country_name = 'Unknown';

		$post['body'] .= "\n<tinyboard flag>".strtolower($country_code)."</tinyboard>".
		"\n<tinyboard flag alt>$country_name</tinyboard>";
	}
	
	if ($config['user_flag'] && isset($_POST['user_flag'])) {
		if (!empty($_POST['user_flag']) ){
			$user_flag = $_POST['user_flag'];
			
			if (!isset($config['user_flags'][$user_flag]))
				error(_('Invalid flag selection!'));

			$flag_alt = isset($user_flag_alt) ? $user_flag_alt : $config['user_flags'][$user_flag];

			$post['body'] .= "\n<tinyboard flag>" . strtolower($user_flag) . "</tinyboard>" .
			"\n<tinyboard flag alt>" . $flag_alt . "</tinyboard>";
		} else if ($config['force_flag']) {
			error(_('You must choose a flag to post on this board!'));
		}
	}

	if ($config['allowed_tags'] && $post['op'] && isset($_POST['tag']) && $_POST['tag'] && isset($config['allowed_tags'][$_POST['tag']])) {
		$post['body'] .= "\n<tinyboard tag>" . $_POST['tag'] . "</tinyboard>";
	}

	if (mysql_version() >= 50503) {
		$post['body_nomarkup'] = $post['body']; // Assume we're using the utf8mb4 charset
	}
	else {
		// MySQL's `utf8` charset only supports up to 3-byte symbols
		// Remove anything >= 0x010000
		
		$chars = preg_split('//u', $post['body'], -1, PREG_SPLIT_NO_EMPTY);
		$post['body_nomarkup'] = '';
		foreach ($chars as $char) {
			$o = 0;
			$ord = ordutf8($char, $o);
			if ($ord >= 0x010000)
				continue;
			$post['body_nomarkup'] .= $char;
		}
	}
	
	$post['tracked_cites'] = markup($post['body'], true, $post['op']);

	
	
	if ($post['has_file']) {
		$allhashes = '';

		foreach ($post['files'] as $key => &$file) {
			if ($post['op'] && $config['allowed_ext_op']) {
				if (!in_array($file['extension'], $config['allowed_ext_op']))
					error($config['error']['unknownext']);
			}
			elseif (!in_array($file['extension'], $config['allowed_ext']) && !in_array($file['extension'], $config['allowed_ext_files']))
				error($config['error']['unknownext']);
			
			$file['is_an_image'] = !in_array($file['extension'], $config['allowed_ext_files']);
			
			// Truncate filename if it is too long
			$file['filename'] = mb_substr($file['filename'], 0, $config['max_filename_len']);
			
			$upload = $file['tmp_name'];
			
			if (!is_readable($upload))
				error($config['error']['nomove']);

			$md5cmd = $config['bsd_md5'] ? 'md5 -r' : 'md5sum';
			if( ($output = shell_exec_error("cat " . escapeshellarg($upload) . " | $md5cmd")) !== false ) {
				$explodedvar = explode(' ', $output);
				$hash = $explodedvar[0];
			} else {
				$hash = md5_file($upload);
			}

			// filter files by MD5
			$query = prepare('SELECT * FROM ``filters`` WHERE `type` = "md5" and `value` = :value');
			$query->bindValue(':value', $hash);
			$result = $query->execute() or error(db_error());
			if ($row = $query->fetch()) {
				$reason = utf8tohtml($row['reason']);
				error("Sorry, cannot upload. Matched MD5 of disallowed file. Reason: {$reason}");
			}

			$file['hash'] = $hash;
			$allhashes .= $hash;
		}
		
		if (count($post['files']) == 1) {
			$post['filehash'] = $hash;
		} else {
			$post['filehash'] = md5($allhashes);
		}
	}
	
	if (!hasPermission($config['mod']['bypass_filters'], $board['uri'])) {
		require_once 'inc/filters.php';	
		
		do_filters($post);
	}
	
	if ($post['has_file']) {
		foreach ($post['files'] as $key => &$file) {
		if ($file['is_an_image'] && $config['ie_mime_type_detection'] !== false) {
			// Check IE MIME type detection XSS exploit
			$buffer = file_get_contents($upload, null, null, null, 255);
			if (preg_match($config['ie_mime_type_detection'], $buffer)) {
				undoImage($post);
				error($config['error']['mime_exploit']);
			}
			
			require_once 'inc/image.php';
			
			// find dimensions of an image using GD
			if (!$size = @getimagesize($file['tmp_name'])) {
				error($config['error']['invalidimg']);
			}
			if ($size[0] > $config['max_width'] || $size[1] > $config['max_height']) {
				error($config['error']['maxsize']);
			}
			
			
			if ($config['convert_auto_orient'] && ($file['extension'] == 'jpg' || $file['extension'] == 'jpeg')) {
				// The following code corrects the image orientation.
				// Currently only works with the 'convert' option selected but it could easily be expanded to work with the rest if you can be bothered.
				if (!($config['redraw_image'] || (($config['strip_exif'] && !$config['use_exiftool']) && ($file['extension'] == 'jpg' || $file['extension'] == 'jpeg')))) {
					if (in_array($config['thumb_method'], array('convert', 'convert+gifsicle', 'gm', 'gm+gifsicle'))) {
						$exif = @exif_read_data($file['tmp_name']);
						$gm = in_array($config['thumb_method'], array('gm', 'gm+gifsicle'));
						if (isset($exif['Orientation']) && $exif['Orientation'] != 1) {
							if ($config['convert_manual_orient']) {
								$error = shell_exec_error(($gm ? 'gm ' : '') . 'convert ' .
									escapeshellarg($file['tmp_name']) . ' ' .
									ImageConvert::jpeg_exif_orientation(false, $exif) . ' ' .
									($config['strip_exif'] ? '+profile "*"' :
										($config['use_exiftool'] ? '' : '+profile "*"')
									) . ' ' .
									escapeshellarg($file['tmp_name']));
								if ($config['use_exiftool'] && !$config['strip_exif']) {
									if ($exiftool_error = shell_exec_error(
										'exiftool -overwrite_original -q -q -orientation=1 -n ' .
											escapeshellarg($file['tmp_name'])))
										error(_('exiftool failed!'), null, $exiftool_error);
								} else {
									// TODO: Find another way to remove the Orientation tag from the EXIF profile
									// without needing `exiftool`.
								}
							} else {
								$error = shell_exec_error(($gm ? 'gm ' : '') . 'convert ' .
										escapeshellarg($file['tmp_name']) . ' -auto-orient ' . escapeshellarg($upload));
							}
							if ($error)
								error(_('Could not auto-orient image!'), null, $error);
							$size = @getimagesize($file['tmp_name']);
							if ($config['strip_exif'])
								$file['exif_stripped'] = true;
						}
					}
				}
			}
			
			// create image object
			$image = new Image($file['tmp_name'], $file['extension'], $size);
			if ($image->size->width > $config['max_width'] || $image->size->height > $config['max_height']) {
				$image->delete();
				error($config['error']['maxsize']);
			}
			
			$file['width'] = $image->size->width;
			$file['height'] = $image->size->height;
			
			if ($config['spoiler_images'] && isset($_POST['spoiler'])) {
				$file['thumb'] = 'spoiler';
				
				$size = @getimagesize($config['spoiler_image']);
				$file['thumbwidth'] = $size[0];
				$file['thumbheight'] = $size[1];
			} elseif ($config['minimum_copy_resize'] &&
				$image->size->width <= $config['thumb_width'] &&
				$image->size->height <= $config['thumb_height'] &&
				$file['extension'] == ($config['thumb_ext'] ? $config['thumb_ext'] : $file['extension'])) {
			
				// Copy, because there's nothing to resize
				copy($file['tmp_name'], $file['thumb']);
			
				$file['thumbwidth'] = $image->size->width;
				$file['thumbheight'] = $image->size->height;
			} else {
				$thumb = $image->resize(
					$config['thumb_ext'] ? $config['thumb_ext'] : $file['extension'],
					$post['op'] ? $config['thumb_op_width'] : $config['thumb_width'],
					$post['op'] ? $config['thumb_op_height'] : $config['thumb_height']
				);
				
				$thumb->to($file['thumb']);
			
				$file['thumbwidth'] = $thumb->width;
				$file['thumbheight'] = $thumb->height;
			
				$thumb->_destroy();
			}
			
			if ($config['redraw_image'] || (!@$file['exif_stripped'] && $config['strip_exif'] && ($file['extension'] == 'jpg' || $file['extension'] == 'jpeg'))) {
				if (!$config['redraw_image'] && $config['use_exiftool']) {
					if($error = shell_exec_error('exiftool -overwrite_original -ignoreMinorErrors -q -q -all= ' .
						escapeshellarg($file['tmp_name'])))
						error(_('Could not strip EXIF metadata!'), null, $error);
				} else {
					$image->to($file['file']);
					$dont_copy_file = true;
				}
			}
			$image->destroy();
		} else {
			// not an image
			//copy($config['file_thumb'], $post['thumb']);
			$file['thumb'] = 'file';

			$size = @getimagesize(sprintf($config['file_thumb'],
				isset($config['file_icons'][$file['extension']]) ?
					$config['file_icons'][$file['extension']] : $config['file_icons']['default']));
			$file['thumbwidth'] = $size[0];
			$file['thumbheight'] = $size[1];
		}
		
		if (!isset($dont_copy_file) || !$dont_copy_file) {
			if (isset($file['file_tmp'])) {
				if (!@rename($file['tmp_name'], $file['file']))
					error($config['error']['nomove']);
				chmod($file['file'], 0644);
			} elseif (!@move_uploaded_file($file['tmp_name'], $file['file']))
				error($config['error']['nomove']);
			}
		}

		if ($config['image_reject_repost']) {
			if ($p = getPostByHash($post['filehash'])) {
				undoImage($post);
				error(sprintf($config['error']['fileexists'], 
					($post['mod'] ? $config['root'] . $config['file_mod'] . '?/' : $config['root']) .
					($board['dir'] . $config['dir']['res'] .
						($p['thread'] ?
							$p['thread'] . '.html#' . $p['id']
						:
							$p['id'] . '.html'
						))
				));
			}
		} else if (!$post['op'] && $config['image_reject_repost_in_thread']) {
			if ($p = getPostByHashInThread($post['filehash'], $post['thread'])) {
				undoImage($post);
				error(sprintf($config['error']['fileexistsinthread'], 
					($post['mod'] ? $config['root'] . $config['file_mod'] . '?/' : $config['root']) .
					($board['dir'] . $config['dir']['res'] .
						($p['thread'] ?
							$p['thread'] . '.html#' . $p['id']
						:
							$p['id'] . '.html'
						))
				));
			}
		}
	}
	
	if (!hasPermission($config['mod']['postunoriginal'], $board['uri']) && $config['robot_enable'] && checkRobot($post['body_nomarkup'])) {
		undoImage($post);
		if ($config['robot_mute']) {
			error(sprintf($config['error']['muted'], mute()));
		} else {
			error($config['error']['unoriginal']);
		}
	}
	
	// Remove board directories before inserting them into the database.
	if ($post['has_file']) {
		foreach ($post['files'] as $key => &$file) {
			$file['file_path'] = $file['file'];
			$file['thumb_path'] = $file['thumb'];
			$file['file'] = mb_substr($file['file'], mb_strlen($config['dir']['img_root'] . $board['dir'] . $config['dir']['img']));
			if ($file['is_an_image'] && $file['thumb'] != 'spoiler')
				$file['thumb'] = mb_substr($file['thumb'], mb_strlen($config['dir']['img_root'] . $board['dir'] . $config['dir']['thumb']));
		}
	}
	
	$post = (object)$post;
	$post->files = array_map(function($a) { return (object)$a; }, $post->files);
	$error = event('post', $post);
	$post->files = array_map(function($a) { return (array)$a; }, $post->files);

	if ($error) {
		undoImage((array)$post);
		error($error);
	}
	$post = (array)$post;

	if ($post['files'])
		$post['files'] = $post['files'];
	$post['num_files'] = sizeof($post['files']);
	
	// Commit the post to the database.
	$post['id'] = $id = post($post);
	
	if (!$tor) insertFloodPost($post);
	
	// Update statistics for this board.
	updateStatisticsForPost( $post );
	
	
	// Handle cyclical threads
	if (!$post['op'] && isset($thread['cycle']) && $thread['cycle']) {
		// Query is a bit weird due to "This version of MariaDB doesn't yet support 'LIMIT & IN/ALL/ANY/SOME subquery'" (MariaDB Ver 15.1 Distrib 10.0.17-MariaDB, for Linux (x86_64))
		$query = prepare(sprintf('SELECT `id` FROM ``posts_%s`` WHERE `thread` = :thread AND `id` NOT IN (SELECT `id` FROM (SELECT `id` FROM ``posts_%s`` WHERE `thread` = :thread ORDER BY `id` DESC LIMIT :limit) i)', $board['uri'], $board['uri']));
		$query->bindValue(':thread', $post['thread']);
		$query->bindValue(':limit', $config['cycle_limit'], PDO::PARAM_INT);
		$query->execute() or error(db_error($query));

		while ($dpost = $query->fetch()) {
			deletePost($dpost['id'], false, false);
		}
	}
	
	if (isset($post['antispam_hash'])) {
		incrementSpamHash($post['antispam_hash']);
	}
	
	if (isset($post['tracked_cites']) && !empty($post['tracked_cites'])) {
		$insert_rows = array();
		foreach ($post['tracked_cites'] as $cite) {
			$insert_rows[] = '(' .
				$pdo->quote($board['uri']) . ', ' . (int)$id . ', ' .
				$pdo->quote($cite[0]) . ', ' . (int)$cite[1] . ')';
		}
		query('INSERT INTO ``cites`` VALUES ' . implode(', ', $insert_rows)) or error(db_error());
	}
	
	if (!$post['op'] && !isset($_POST['no-bump']) && strtolower($post['email']) != 'sage' && !$thread['sage'] && ($thread['cycle'] || $config['reply_limit'] == 0 || $numposts['replies']+1 < $config['reply_limit'])) {
		bumpThread($post['thread']);
	}
	
	if (isset($_SERVER['HTTP_REFERER'])) {
		// Tell Javascript that we posted successfully
		if (isset($_COOKIE[$config['cookies']['js']]))
			$js = json_decode($_COOKIE[$config['cookies']['js']]);
		else
			$js = (object) array();
		// Tell it to delete the cached post for referer
		$js->{$_SERVER['HTTP_REFERER']} = true;
		// Encode and set cookie
		setcookie($config['cookies']['js'], json_encode($js), 0, $config['cookies']['jail'] ? $config['cookies']['path'] : '/', null, false, false);
	}
	
	$root = $post['mod'] ? $config['root'] . $config['file_mod'] . '?/' : $config['root'];
	
	if ($noko) {
		$redirect = $root . $board['dir'] . $config['dir']['res'] .
			sprintf($config['file_page'], $post['op'] ? $id:$post['thread']) . (!$post['op'] ? '#' . $id : '');
	   	
		if (!$post['op'] && isset($_SERVER['HTTP_REFERER'])) {
			$regex = array(
				'board' => str_replace('%s', '(\w{1,8})', preg_quote($config['board_path'], '/')),
				'page' => str_replace('%d', '(\d+)', preg_quote($config['file_page'], '/')),
				'page50' => str_replace('%d', '(\d+)', preg_quote($config['file_page50'], '/')),
				'res' => preg_quote($config['dir']['res'], '/'),
			);

			if (preg_match('/\/' . $regex['board'] . $regex['res'] . $regex['page50'] . '([?&].*)?$/', $_SERVER['HTTP_REFERER'])) {
				$redirect = $root . $board['dir'] . $config['dir']['res'] .
					sprintf($config['file_page50'], $post['op'] ? $id:$post['thread']) . (!$post['op'] ? '#' . $id : '');
			}
		}
	} else {
		$redirect = $root . $board['dir'] . $config['file_index'];
		
	}

	buildThread($post['op'] ? $id : $post['thread']);
	
	if ($config['syslog'])
		_syslog(LOG_INFO, 'New post: /' . $board['dir'] . $config['dir']['res'] .
			sprintf($config['file_page'], $post['op'] ? $id : $post['thread']) . (!$post['op'] ? '#' . $id : ''));
	
	if (!$post['mod']) header('X-Associated-Content: "' . $redirect . '"');

	if (!isset($_POST['json_response'])) {
		
                if(isset($post['mod']) && !$post['mod']){
                        $redirect = $config['webdomain'] . $redirect;
                }		
		
		header('Location: ' . $redirect, true, $config['redirect_http']);
	} else {
		header('Content-Type: text/json; charset=utf-8');
		echo json_encode(array(
			'redirect' => $redirect,
			'noko' => $noko,
			'id' => $id
		));
	}
	
	if ($config['try_smarter'] && $post['op'])
		$build_pages = range(1, $config['max_pages']);
	
	if ($post['op'])
		clean();
	
	event('post-after', $post);
	
	// We are already done, let's continue our heavy-lifting work in the background (if we run off FastCGI)
	if (function_exists('fastcgi_finish_request')) {
		@fastcgi_finish_request();
	}

	buildIndex();
	
	if ($post['op']) {
		rebuildThemes('post-thread', $board['uri']);
	}
	else {
		rebuildThemes('post', $board['uri']);
	}
}
elseif (isset($_POST['appeal'])) {
	if (!isset($_POST['ban_id']))
		error($config['error']['bot']);
	
	$ban_id = (int)$_POST['ban_id'];
	$identity = getIdentity();
	$bans = Bans::find($identity);
	foreach ($bans as $_ban) {
		if ($_ban['id'] == $ban_id) {
			$ban = $_ban;
			break;
		}
	}
	
	if (!isset($ban)) {
		error(_("That ban doesn't exist or is not for you."));
	}
	
	if ($ban['expires'] && $ban['expires'] - $ban['created'] <= $config['ban_appeals_min_length']) {
		error(_("You cannot appeal a ban of this length."));
	}
	
	$query = query("SELECT `denied` FROM ``ban_appeals`` WHERE `ban_id` = $ban_id") or error(db_error());
	$ban_appeals = $query->fetchAll(PDO::FETCH_COLUMN);
	
	if (count($ban_appeals) >= $config['ban_appeals_max']) {
		error(_("You cannot appeal this ban again."));
	}
	
	foreach ($ban_appeals as $is_denied) {
		if (!$is_denied)
			error(_("There is already a pending appeal for this ban."));
	}
	
	$query = prepare("INSERT INTO ``ban_appeals`` VALUES (NULL, :ban_id, :time, :message, 0)");
	$query->bindValue(':ban_id', $ban_id, PDO::PARAM_INT);
	$query->bindValue(':time', time(), PDO::PARAM_INT);
	$query->bindValue(':message', $_POST['appeal']);
	$query->execute() or error(db_error($query));
	
	displayBan($ban);
}
else {
	if (!file_exists($config['has_installed'])) {
		header('Location: install.php', true, $config['redirect_http']);
	} else {
		// They opened post.php in their browser manually.
		error($config['error']['nopost']);
	}
}
