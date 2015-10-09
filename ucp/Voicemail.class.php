<?php
/**
* This is the User Control Panel Object.
*
* Copyright (C) 2013 Schmooze Com, INC
* Copyright (C) 2013 Andrew Nagy <andrew.nagy@schmoozecom.com>
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License as
* published by the Free Software Foundation, either version 3 of the
* License, or (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* @package   FreePBX UCP BMO
* @author   Andrew Nagy <andrew.nagy@schmoozecom.com>
* @license   AGPL v3
*/
namespace UCP\Modules;
use \UCP\Modules as Modules;

class Voicemail extends Modules{
	protected $module = 'Voicemail';
	private $limit = 15;
	private $break = 5;
	private $boxes = array();
	private $extensions = array();
	private $user = array();

	function __construct($Modules) {
		$this->Modules = $Modules;
		$this->Vmx = $this->UCP->FreePBX->Voicemail->Vmx;
		if($this->UCP->Session->isMobile) {
			$this->limit = 7;
		}

		$this->user = $this->UCP->User->getUser();
		$this->enabled = $this->UCP->getCombinedSettingByID($this->user['id'],$this->module,'enable');
		$this->extensions = $this->UCP->getCombinedSettingByID($this->user['id'],$this->module,'assigned');
		$this->playback = $this->UCP->getCombinedSettingByID($this->user['id'],$this->module,'playback');
		$this->playback = !is_null($this->playback) ? $this->playback : true;
		$this->download = $this->UCP->getCombinedSettingByID($this->user['id'],$this->module,'download');
		$this->download = !is_null($this->download) ? $this->download : true;
		$this->settings = $this->UCP->getCombinedSettingByID($this->user['id'],$this->module,'settings');
		$this->settings = !is_null($this->settings) ? $this->settings : true;
		$this->greetings = $this->UCP->getCombinedSettingByID($this->user['id'],$this->module,'greetings');
		$this->greetings = !is_null($this->greetings) ? $this->greetings : true;
	}

	function getDisplay() {
		$ext = !empty($_REQUEST['sub']) ? $_REQUEST['sub'] : '';
		if(!empty($ext) && !$this->_checkExtension($ext)) {
			return _("Forbidden");
		}
		$reqFolder = !empty($_REQUEST['folder']) ? $_REQUEST['folder'] : 'INBOX';
		$view = !empty($_REQUEST['view']) ? $_REQUEST['view'] : 'folder';
		$page = !empty($_REQUEST['page']) ? $_REQUEST['page'] : 1;
		$folders = $this->UCP->FreePBX->Voicemail->getFolders();
		$messages = array();

		foreach($folders as $folder) {
			$folders[$folder['folder']]['count'] = $this->UCP->FreePBX->Voicemail->getMessagesCountByExtensionFolder($ext,$folder['folder']);
		}

		$displayvars = array(
			"showPlayback" => $this->playback,
			"showDownload" => $this->download,
			"showSettings" => $this->settings,
			"showGreetings" => $this->greetings
		);
		$displayvars['ext'] = $ext;
		$displayvars['folders'] = $folders;

		$sf = $this->UCP->FreePBX->Media->getSupportedFormats();
		$html = "<script>var showDownload = ".json_decode($this->download)."; var showPlayback = ".json_decode($this->playback).";var supportedRegExp = '".implode("|",array_keys($sf['in']))."';var supportedHTML5 = '".implode(",",$this->UCP->FreePBX->Media->getSupportedHTML5Formats())."';var extension = '".$ext."'; var mailboxes = ".json_encode($this->extensions).";</script>";
		$html .= $this->load_view(__DIR__.'/views/header.php',$displayvars);

		if(!empty($this->UCP->FreePBX->Voicemail->displayMessage['message'])) {
			$displayvars['message'] = $this->UCP->FreePBX->Voicemail->displayMessage;
		}
		if($view == "settings" && !$this->settings) {
			$view = "";
		}
		if($view == "greetings" && !$this->greetings) {
			$view = "";
		}
		switch($view) {
			case "settings":
				$displayvars['settings'] = $this->UCP->FreePBX->Voicemail->getVoicemailBoxByExtension($ext);
				$mainDisplay= $this->load_view(__DIR__.'/views/settings.php',$displayvars);
				$displayvars['activeList'] = 'settings';
			break;
			case "greetings":
				$displayvars['supported'] = $sf;
				$displayvars['settings'] = $this->UCP->FreePBX->Voicemail->getVoicemailBoxByExtension($ext);
				$displayvars['greetings'] = $this->UCP->FreePBX->Voicemail->getGreetingsByExtension($ext);
				$displayvars['short_greetings'] = $this->UCP->FreePBX->Voicemail->greetings;

				$mainDisplay= $this->load_view(__DIR__.'/views/greetings.php',$displayvars);
				$displayvars['activeList'] = 'greetings';
			break;
			case "folder":
				$displayvars['settings'] = $this->UCP->FreePBX->Voicemail->getVoicemailBoxByExtension($ext);
				$final = array();
				$c = $folders[$reqFolder]['count'];
				$displayvars['messages'] = $final;
				$displayvars['folder'] = $reqFolder;
				$totalPages = (ceil($c/$this->limit) > 0) ? ceil($c/$this->limit) : 1;
				$displayvars['pagnation'] = $this->UCP->Template->generatePagnation($totalPages,$page,"?display=dashboard&mod=voicemail&sub=".$ext."&folder=".$reqFolder."&view=folder",$this->break);
				$mainDisplay = $this->load_view(__DIR__.'/views/mailbox.php',$displayvars);
				$displayvars['activeList'] = $reqFolder;
			default:
			break;
		}


		$html .= $this->load_view(__DIR__.'/views/nav.php',$displayvars);
		$html .= $mainDisplay;
		$html .= $this->load_view(__DIR__.'/views/footer.php',$displayvars);
		return $html;
	}

	function poll() {
		$boxes = $this->getMailboxCount($this->extensions);
		return array("status" => ($boxes['total'] > 0), "total" => $boxes['total'], "boxes" => isset($boxes['extensions']) ? $boxes['extensions'] : '');
	}

	public function getSettingsDisplay($ext) {
		if($this->Vmx->isInitialized($ext) && $this->Vmx->isEnabled($ext)) {
			$displayvars = array(
				'settings' => $this->Vmx->getSettings($ext),
				'fmfm' => 'FM'.$ext
			);
			$out = array(
				array(
					"title" => _('VmX Locator'),
					"content" => $this->load_view(__DIR__.'/views/vmx.php',$displayvars),
					"size" => 6,
					"order" => 1
				)
			);
			return $out;
		} else {
			return array();
		}
	}

	/**
	* Determine what commands are allowed
	*
	* Used by Ajax Class to determine what commands are allowed by this class
	*
	* @param string $command The command something is trying to perform
	* @param string $settings The Settings being passed through $_POST or $_PUT
	* @return bool True if pass
	*/
	function ajaxRequest($command, $settings) {
		switch($command) {
			case 'grid':
			case 'listen':
			case 'moveToFolder':
			case 'delete':
			case 'forwards':
			case 'callme':
			case 'forward':
			case 'refreshfoldercount';
				return $this->_checkExtension($_REQUEST['ext']);
			break;
			case "gethtml5":
			case "playback":
				return $this->playback && $this->_checkExtension($_REQUEST['ext']);
			break;
			case 'download':
				return $this->download && $this->_checkExtension($_REQUEST['ext']);
			break;
			case 'record':
			case "copy":
			case 'upload':
				return $this->greetings && $this->_checkExtension($_REQUEST['ext']);
			break;
			case 'savesettings':
				return $this->settings && $this->_checkExtension($_REQUEST['ext']);
			break;
			case 'vmxsettings':
				$ext = $_REQUEST['ext'];
				return $this->_checkExtension($ext) && $this->Vmx->isInitialized($ext) && $this->Vmx->isEnabled($ext);
			break;
			case 'checkboxes':
				return true;
			default:
				return false;
			break;
		}
	}

	/**
	* The Handler for all ajax events releated to this class
	*
	* Used by Ajax Class to process commands
	*
	* @return mixed Output if success, otherwise false will generate a 500 error serverside
	*/
	function ajaxHandler() {
		$return = array("status" => false, "message" => "");
		switch($_REQUEST['command']) {
			case 'refreshfoldercount':
				$folders = $this->UCP->FreePBX->Voicemail->getFolders();
				foreach($folders as $folder) {
					$folders[$folder['folder']]['count'] = $this->UCP->FreePBX->Voicemail->getMessagesCountByExtensionFolder($_REQUEST['ext'],$folder['folder']);
				}
				return array("status" => true, "folders" => $folders);
			break;
			case 'gethtml5':
				$media = $this->UCP->FreePBX->Media();
				$message = $this->UCP->FreePBX->Voicemail->getMessageByMessageIDExtension($_REQUEST['msg_id'],$_REQUEST['ext']);
				$file = $message['path']."/".$message['file'];
				if (file_exists($file))	{
					$media->load($file);
					$files = $media->generateHTML5();
					$final = array();
					foreach($files as $format => $name) {
						$final[$format] = "index.php?quietmode=1&module=voicemail&command=playback&file=".$name."&ext=".$_REQUEST['ext'];
					}
					return array("status" => true, "files" => $final);
				} else {
					return array("status" => false, "message" => _("File does not exist"));
				}
			break;
			case 'grid':
				$folder = $_REQUEST['folder'];
				$limit = $_REQUEST['limit'];
				$order = $_REQUEST['order'];
				$orderby = !empty($_REQUEST['sort']) ? $_REQUEST['sort'] : "date";
				$ext = $_REQUEST['ext'];
				$search = !empty($_REQUEST['search']) ? $_REQUEST['search'] : "";
				$offset = $_REQUEST['offset'];
				$data = $this->UCP->FreePBX->Voicemail->getMessagesByExtensionFolder($ext,$folder,$order,$orderby,$offset,$limit);
				return array(
					"total" => $this->UCP->FreePBX->Voicemail->getMessagesCountByExtensionFolder($ext,$folder),
					"rows" => !empty($data['messages']) ? $data['messages'] : array()
				);
			break;
			case 'callme':
				$validUsers = array();
				$users = $this->UCP->FreePBX->Voicemail->getUsersList(true);
				foreach($users as $user) {
					$validUsers[] = $user[0];
				}
				if(!in_array($_POST['to'],$validUsers)) {
					$return['message'] = _("Invalid Recipient");
					return $return;
				}
				$message = $this->UCP->FreePBX->Voicemail->getMessageByMessageIDExtension($_POST['id'],$_REQUEST['ext']);
				if(!empty($message)) {
					$astman = $this->UCP->FreePBX->astman;
					$status = $astman->originate(array(
						"Channel" => "Local/".$_POST['to']."@from-internal",
						"Exten" => "s",
						"Context" => "vm-callme",
						"Priority" => 1,
						"Async" => "yes",
						"CallerID" => _("Voicemail Message") . " <"._("VMAIL").">",
						"Variable" => "MSG=".$message['path'] . "/" . $message['fid'].",MBOX=".$_REQUEST['ext']
					));
					if($status['Response'] == "Success") {
						$return['status'] = true;
					} else {
						$return['message'] = $status['Message'];
					}
				}
				$return['message'] = ("Invalid Message ID");
				return $return;
			break;
			case 'forward':
				$validUsers = array();
				$users = $this->UCP->FreePBX->Voicemail->getUsersList(true);
				foreach($users as $user) {
					$validUsers[] = $user[0];
				}
				if(!in_array($_POST['to'],$validUsers)) {
					$return['message'] = _("Invalid Recipient");
					return $return;
				}
				$message = $this->UCP->FreePBX->Voicemail->getMessageByMessageIDExtension($_POST['id'],$_REQUEST['ext']);
				if(!empty($message)) {
					$this->UCP->FreePBX->Voicemail->forwardMessageByExtension($_POST['id'],$_REQUEST['ext'],$_POST['to']);
				}
				$return['message'] = ("Invalid Message ID");
				return $return;
			break;
			case 'forwards':
				$return = array();
				$users = $this->UCP->FreePBX->Voicemail->getUsersList(true);
				$search = !empty($_REQUEST['search']) ? $_REQUEST['search'] : '';
				foreach($users as $user) {
					if(preg_match('/'.$search.'/i',$user[1]) || preg_match('/'.$search.'/i',$user[0])) {
						$return[] = array(
							"value" => $user[0],
							"text" => $user[1] . " (".$user[0].")"
						);
					}
				}
			break;
			case 'vmxsettings':
				switch($_POST['settings']['key']) {
					case 'vmx-usewhen-unavailable':
						$m = ($_POST['settings']['value'] == 'true') ? 'enabled' : 'disabled';
						$this->Vmx->setState($_POST['ext'],'unavail',$m);
					break;
					case 'vmx-usewhen-busy':
						$m = ($_POST['settings']['value'] == 'true') ? 'enabled' : 'disabled';
						$this->Vmx->setState($_POST['ext'],'busy',$m);
					break;
					case 'vmx-usewhen-temp':
						$m = ($_POST['settings']['value'] == 'true') ? 'enabled' : 'disabled';
						$this->Vmx->setState($_POST['ext'],'temp',$m);
					break;
					case 'vmx-opt0':
						$this->Vmx->setMenuOpt($_POST['ext'],$_POST['settings']['value'],'0','unavail');
						$this->Vmx->setMenuOpt($_POST['ext'],$_POST['settings']['value'],'0','busy');
						$this->Vmx->setMenuOpt($_POST['ext'],$_POST['settings']['value'],'0','temp');
					break;
					case 'vmx-opt1':
						if(empty($_POST['settings']['value'])) {
							$this->Vmx->setFollowMe($_POST['ext'],'1','unavail');
							$this->Vmx->setFollowMe($_POST['ext'],'1','busy');
							$this->Vmx->setFollowMe($_POST['ext'],'1','temp');
						} else {
							$this->Vmx->setMenuOpt($_POST['ext'],$_POST['settings']['value'],'1','unavail');
							$this->Vmx->setMenuOpt($_POST['ext'],$_POST['settings']['value'],'1','busy');
							$this->Vmx->setMenuOpt($_POST['ext'],$_POST['settings']['value'],'1','temp');
						}
					break;
					case 'vmx-opt2':
						$this->Vmx->setMenuOpt($_POST['ext'],$_POST['settings']['value'],'2','unavail');
						$this->Vmx->setMenuOpt($_POST['ext'],$_POST['settings']['value'],'2','busy');
						$this->Vmx->setMenuOpt($_POST['ext'],$_POST['settings']['value'],'2','temp');
					break;
					default:
						return false;
					break;
				}
				$return = array("status" => true, "message" => "Saved", "alert" => "success");
			break;
			case 'checkboxes':
				$boxes = $this->getMailboxCount($this->extensions);
				return array("status" => true, "total" => $boxes['total'], "boxes" => $boxes['extensions']);
			break;
			case 'moveToFolder':
				$ext = $_POST['ext'];
				$status = $this->UCP->FreePBX->Voicemail->moveMessageByExtensionFolder($_POST['msg'],$ext,$_POST['folder']);
				$return = array("status" => $status, "message" => "");
			break;
			case 'delete':
				$ext = $_POST['ext'];
				$status = $this->UCP->FreePBX->Voicemail->deleteMessageByID($_POST['msg'],$ext);
				$return = array("status" => $status, "message" => "");
			break;
			case 'savesettings':
				$ext = $_POST['ext'];
				$saycid = ($_POST['saycid'] == 'true') ? true : false;
				$envelope = ($_POST['envelope'] == 'true') ? true : false;
				$delete = ($_POST['vmdelete'] == 'true') ? true : false;
				$attach = ($_POST['attach'] == 'true') ? true : false;
				$status = $this->UCP->FreePBX->Voicemail->saveVMSettingsByExtension($ext,$_POST['pwd'],$_POST['email'],$_POST['pager'],$saycid,$envelope, $attach, $delete);
				$return = array("status" => $status, "message" => "");
			break;
			case "upload":
				foreach ($_FILES["files"]["error"] as $key => $error) {
					if ($error == UPLOAD_ERR_OK) {
						$tmp_path = sys_get_temp_dir();
						$tmp_path = !empty($tmp_path) ? $tmp_path : '/tmp';

						$extension = pathinfo($_FILES["files"]["name"][$key], PATHINFO_EXTENSION);
						$supported = $this->UCP->FreePBX->Media->getSupportedFormats();
						if(in_array($extension,$supported['in'])) {
							$tmp_name = $_FILES["files"]["tmp_name"][$key];
							$name = $_FILES["files"]["name"][$key];
							if(!file_exists($tmp_path."/vmtmp")) {
								mkdir($tmp_path."/vmtmp");
							}
							move_uploaded_file($tmp_name, $tmp_path."/vmtmp/$name");
							if(!file_exists($tmp_path."/vmtmp/$name")) {
								$return = array("status" => false, "message" => sprintf(_("Voicemail not moved to %s"),$tmp_path."/vmtmp/".$name));
								break;
							}
							$this->UCP->FreePBX->Voicemail->saveVMGreeting($_REQUEST['ext'],$_REQUEST['type'],$extension,$tmp_path."/vmtmp/$name");
						} else {
							$return = array("status" => false, "message" => _("Unsupported file format"));
							break;
						}
					}
				}
				$return = array("status" => true, "message" => "");
			break;
			case "copy":
				$status = $this->UCP->FreePBX->Voicemail->copyVMGreeting($_POST['ext'],$_POST['source'],$_POST['target']);
				$return = array("status" => $status, "message" => "");
			break;
			case "record":
				if ($_FILES["file"]["error"] == UPLOAD_ERR_OK) {
					$tmp_path = sys_get_temp_dir();
					$tmp_path = !empty($tmp_path) ? $tmp_path : '/tmp';

					$tmp_name = $_FILES["file"]["tmp_name"];
					$name = $_FILES["file"]["name"];
					if(!file_exists($tmp_path."/vmtmp")) {
						mkdir($tmp_path."/vmtmp");
					}
					move_uploaded_file($tmp_name, $tmp_path."/vmtmp/$name");
					if(!file_exists($tmp_path."/vmtmp/$name")) {
						$return = array("status" => false, "message" => sprintf(_("Voicemail not moved to %s"),$tmp_path."/vmtmp/".$name));
						break;
					}
					$contents = file_get_contents($tmp_path."/vmtmp/$name");
					if(empty($contents)) {
						$return = array("status" => false, "message" => sprintf(_("Voicemail was empty: %s"),$tmp_path."/vmtmp/".$name));
						break;
					}
					unlink($tmp_path."/vmtmp/$name");
					$this->UCP->FreePBX->Voicemail->saveVMGreeting($_REQUEST['ext'],$_REQUEST['type'],'wav',$contents);
				}	else {
					$return = array("status" => false, "message" => _("Unknown Error"));
					break;
				}
				$return = array("status" => true, "message" => "");
			break;
			default:
				return false;
			break;
		}
		return $return;
	}

	/**
	* The Handler for quiet events
	*
	* Used by Ajax Class to process commands in which custom processing is needed
	*
	* @return mixed Output if success, otherwise false will generate a 500 error serverside
	*/
	function ajaxCustomHandler() {
		switch($_REQUEST['command']) {
			case "playback":
				$media = $this->UCP->FreePBX->Media();
				$media->getHTML5File($_REQUEST['file']);
				return true;
			break;
			case "download":
				$msgid = $_REQUEST['msgid'];
				$ext = $_REQUEST['ext'];
				$this->downloadFile($msgid,$ext);
				return true;
			break;
			default:
				return false;
			break;
		}
		return false;
	}

	/**
	 * Get the main badge
	 * @return int	Number for the badge
	 */
	public function getBadge() {
		$boxes = $this->getMailboxCount($this->extensions);
		return $boxes['total'];
	}

	/**
	 * Get UCP Menu Items
	 * @return array Array of menu items
	 */
	public function getMenuItems() {
		if(!$this->enabled) {
			return array();
		}
		$extensions = $this->extensions;
		$menu = array();
		if(!empty($extensions)) {
			$menu = array(
				"rawname" => "voicemail",
				"name" => _("Voicemail"),
				"badge" => $this->getBadge()
			);
			$boxes = $this->getMailboxCount($this->extensions);
			foreach($extensions as $extension) {
				$data = $this->UCP->FreePBX->Core->getDevice($extension);
				if(empty($data) || empty($data['description'])) {
					$data = $this->UCP->FreePBX->Core->getUser($extension);
					$name = $data['name'];
				} else {
					$name = $data['description'];
				}
				$o = $this->UCP->FreePBX->Voicemail->getVoicemailBoxByExtension($extension);
				if(!empty($o) && isset($boxes['extensions'][$extension])) {
					$menu["menu"][] = array(
						"rawname" => $extension,
						"name" => $extension . " - " . $name,
						"badge" => (int)$boxes['extensions'][$extension]
					);
				}
			}
		}
		return !empty($menu["menu"]) ? $menu : array();
	}

	/**
	 * Download a file to listen to on your desktop
	 * @param  string $msgid The message id
	 * @param  int $ext   Extension wanting to listen to
	 */
	private function downloadFile($msgid,$ext) {
		if(!$this->_checkExtension($ext)) {
			header("HTTP/1.0 403 Forbidden");
			echo _("Forbidden");
			exit;
		}
		$message = $this->UCP->FreePBX->Voicemail->getMessageByMessageIDExtension($msgid,$ext);
		if(!empty($message)) {
			$file = $message['path'] . "/" . $message['file'];

			if (is_file($file)){
				header("Content-length: " . filesize($file));
				header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
				header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
				header('Content-Disposition: attachment;filename="' . $message['file'].'"');
				readfile($file);
				return;
			}
		}
		header("HTTP/1.0 404 Not Found");
		echo _("Not Found");
		exit;
	}

	/**
	 * Check to make sure extension is valid for this user
	 * @param  int $extension The extension number
	 * @return boolean            True is do
	 */
	private function _checkExtension($extension) {
		if(!$this->enabled) {
			return false;
		}
		$extensions = $this->extensions;
		return in_array($extension,$extensions);
	}

	/**
	 * Get mailbox count
	 * @return array Count of the mailboxes
	 */
	private function getMailboxCount() {
		$boxes = array();
		$total = 0;
		$extensions = $this->extensions;
		$extensions = is_array($extensions)?$extensions:array();
		foreach($extensions as $extension) {
			$fvm = $this->UCP->FreePBX->Voicemail->getVoicemailBoxByExtension($extension);
			if(empty($fvm['vmcontext'])) {
				continue;
			}
			$mailbox = $this->UCP->FreePBX->astman->MailboxCount($extension);
			if($mailbox['Response'] == "Success" && !empty($mailbox['Mailbox']) && $mailbox['Mailbox'] == $extension) {
				$total = $total + (int)$mailbox['NewMessages'];
				$boxes['extensions'][$extension] = (int)$mailbox['NewMessages'];
			}
		}
		$boxes['total'] = $total;
		return $boxes;
	}
}
