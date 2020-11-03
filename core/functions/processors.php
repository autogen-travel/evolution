<?php

use EvolutionCMS\Models\SiteTmplvarAccess;
use EvolutionCMS\Models\SiteTmplvarTemplate;

if (!function_exists('duplicateDocument')) {
    /**
     * @param int $docid
     * @param null|int $parent
     * @param int $_toplevel
     * @return int
     */
    function duplicateDocument($docid, $parent = null, $_toplevel = 0)
    {
        $modx = evolutionCMS();
        global $_lang;

        // invoke OnBeforeDocDuplicate event
        $evtOut = $modx->invokeEvent('OnBeforeDocDuplicate', array(
            'id' => $docid
        ));

        // if( !in_array( 'false', array_values( $evtOut ) ) ){}
        // TODO: Determine necessary handling for duplicateDocument "return $newparent" if OnBeforeDocDuplicate were able to conditially control duplication
        // [DISABLED]: Proceed with duplicateDocument if OnBeforeDocDuplicate did not return false via: $event->output('false');

        $userID = $modx->getLoginUserID();

        // Grab the original document

        $content = \EvolutionCMS\Models\SiteContent::query()->find($docid)->toArray();

        // Handle incremental ID
        switch ($modx->getConfig('docid_incrmnt_method')) {
            case '1':
                $minId = \EvolutionCMS\Models\SiteContent::query()
                    ->leftJoin('site_content as T1', \DB::raw('(') . 'site_content.id' . \DB::raw('+1)'), '=', 'T1.id')
                    ->whereNull('T1.id')->min('site_content.id');

                $content['id'] = $minId + 1;
                break;
            case '2':
                $content['id'] = \EvolutionCMS\Models\SiteContent::query()->max('id') + 1;
                break;

            default:
                unset($content['id']); // remove the current id.
        }

        // Once we've grabbed the document object, start doing some modifications
        if ($_toplevel == 0) {
            // count duplicates
            $pagetitle = \EvolutionCMS\Models\SiteContent::find($docid)->pagetitle;

            $count = \EvolutionCMS\Models\SiteContent::query()->where('pagetitle', 'LIKE', '%'.$pagetitle.' Duplicate%')->count();

            if ($count >= 1) {
                $count = ' ' . ($count + 1);
            } else {
                $count = '';
            }

            $content['pagetitle'] = sprintf(
                '%s%s %s'
                , $_lang['duplicated_el_suffix']
                , $count, $content['pagetitle']
            );
            $content['alias'] = null;
        } elseif ($modx->getConfig('friendly_urls') == 0 || $modx->getConfig('allow_duplicate_alias') == 0) {
            $content['alias'] = null;
        }

        // change the parent accordingly
        if ($parent !== null) {
            $content['parent'] = $parent;
        }

        // Change the author
        $content['createdby'] = $userID;
        $content['createdon'] = time();
        // Remove other modification times
        $content['editedby'] = $content['editedon'] = $content['deleted'] = $content['deletedby'] = $content['deletedon'] = 0;

        // Set the published status to unpublished by default (see above ... commit #3388)
        $content['published'] = $content['pub_date'] = 0;


        // Duplicate the Document
        $newparent = \EvolutionCMS\Models\SiteContent::query()->insertGetId($content);

        // duplicate document's TVs
        duplicateTVs($docid, $newparent);
        duplicateAccess($docid, $newparent);

        // invoke OnDocDuplicate event
        $evtOut = $modx->invokeEvent('OnDocDuplicate', array(
            'id' => $docid,
            'new_id' => $newparent
        ));

        // Start duplicating all the child documents that aren't deleted.
        $_toplevel++;
        $documents = \EvolutionCMS\Models\SiteContent::where('parent', $docid)->where('deleted', 0)->orderBy('id')->get();

        foreach ($documents as $document) {
            duplicateDocument($document->id, $newparent, $_toplevel);
        }

        // return the new doc id
        return $newparent;
    }
}

if (!function_exists('duplicateTVs')) {
    /**
     * Duplicate Document TVs
     *
     * @param int $oldid
     * @param int $newid
     */
    function duplicateTVs($oldid, $newid)
    {
        $oldTvs = \EvolutionCMS\Models\SiteTmplvarContentvalue::query()->where('contentid', $oldid)->get();
        foreach ($oldTvs->toArray() as $oldTv) {
            unset($oldTv['id']);
            $oldTv['contentid'] = $newid;
            \EvolutionCMS\Models\SiteTmplvarContentvalue::query()->insert($oldTv);
        }
    }
}

if (!function_exists('duplicateAccess')) {
    /**
     * Duplicate Document Access Permissions
     *
     * @param int $oldid
     * @param int $newid
     */
    function duplicateAccess($oldid, $newid)
    {
        $modx = evolutionCMS();
        $oldDocGroups = \EvolutionCMS\Models\DocumentGroup::query()->where('document', $oldid)->get();
        foreach ($oldDocGroups->toArray() as $oldDocGroup) {
            unset($oldDocGroup['id']);
            $oldDocGroup['document'] = $newid;
            \EvolutionCMS\Models\DocumentGroup::query()->insert($oldDocGroup);
        }
    }
}

if (!function_exists('evalModule')) {
    /**
     * evalModule
     *
     * @param string $moduleCode
     * @param array $params
     * @return string
     */
    function evalModule($moduleCode, $params)
    {
        $modx = evolutionCMS();
        $modx->event->params = &$params; // store params inside event object
        if (is_array($params)) {
            extract($params, EXTR_SKIP);
        }
        ob_start();
        $mod = eval($moduleCode);
        $msg = ob_get_contents();
        ob_end_clean();
        if (isset($php_errormsg)) {
            $error_info = error_get_last();
            switch ($error_info['type']) {
                case E_NOTICE :
                    $error_level = 1;
                case E_USER_NOTICE :
                    break;
                case E_DEPRECATED :
                case E_USER_DEPRECATED :
                case E_STRICT :
                    $error_level = 2;
                    break;
                default:
                    $error_level = 99;
            }
            if ($modx->getConfig('error_reporting') >= 99 || 2 < $error_level) {
                $modx->messageQuit(
                    'PHP Parse Error'
                    , ''
                    , true
                    , $error_info['type']
                    , $error_info['file']
                    , sprintf('%s - Module', $_SESSION['itemname'])
                    , $error_info['message']
                    , $error_info['line']
                    , $msg
                );
                $modx->event->alert(
                    sprintf(
                        'An error occurred while loading. Please see the event log for more information<p>%s</p>'
                        , $msg
                    )
                );
            }
        }
        unset($modx->event->params);

        return $mod . $msg;
    }
}

if (!function_exists('allChildren')) {
    /**
     * @param int $currDocID
     * @return array
     */
    function allChildren($currDocID)
    {
        $children = array();
        $found = collect();

        $docs = EvolutionCMS\Models\SiteContent::withTrashed()
            ->where('parent', '=', $currDocID)
            ->pluck('id')
            ->toArray();

        foreach ($docs as $id) {
            $children[] = $id;
            $found->push(allChildren($id));
        }

        return array_merge($children, $found->collapse()->toArray());
    }
}

if (!function_exists('jsAlert')) {
    /**
     * show javascript alert
     *
     * @param string $msg
     */
    function jsAlert($msg)
    {
        if ((int)get_by_key($_POST, 'ajax', 0) !== 1) {
            echo sprintf(
                '<script>window.setTimeout("alert(\'%s\')",10);history.go(-1)</script>'
                , addslashes($msg)
            );
        } else {
            echo $msg . "\n";
        }
    }
}

if (!function_exists('login')) {
    /**
     * @param string $username
     * @param string $givenPassword
     * @param string $dbasePassword
     * @return bool
     */
    function login($username, $givenPassword, $dbasePassword)
    {
        $modx = evolutionCMS();

        return $modx->getPasswordHash()->CheckPassword($givenPassword, $dbasePassword);
    }
}

if (!function_exists('loginV1')) {
    /**
     * @param int $internalKey
     * @param string $givenPassword
     * @param string $dbasePassword
     * @param string $username
     * @return bool
     */
    function loginV1($internalKey, $givenPassword, $dbasePassword, $username)
    {
        $modx = evolutionCMS();

        $user_algo = $modx->getManagerApi()->getV1UserHashAlgorithm($internalKey);

        if (!isset($modx->config['pwd_hash_algo']) || empty($modx->config['pwd_hash_algo'])) {
            $modx->setConfig('pwd_hash_algo', 'UNCRYPT');
        }

        if ($user_algo !== $modx->getConfig('pwd_hash_algo')) {
            $bk_pwd_hash_algo = $modx->getConfig('pwd_hash_algo');
            $modx->setConfig('pwd_hash_algo', $user_algo);
        }

        if ($dbasePassword != $modx->getManagerApi()->genV1Hash($givenPassword, $internalKey)) {
            return false;
        }

        updateNewHash($username, $givenPassword);

        return true;
    }
}

if (!function_exists('loginMD5')) {
    /**
     * @param int $internalKey
     * @param string $givenPassword
     * @param string $dbasePassword
     * @param string $username
     * @return bool
     */
    function loginMD5($internalKey, $givenPassword, $dbasePassword, $username)
    {
        $modx = evolutionCMS();

        if ($dbasePassword != md5($givenPassword)) {
            return false;
        }
        updateNewHash($username, $givenPassword);

        return true;
    }
}

if (!function_exists('updateNewHash')) {
    /**
     * @param string $username
     * @param string $password
     */
    function updateNewHash($username, $password)
    {
        $modx = evolutionCMS();

        $field = array();
        $field['password'] = $modx->getPasswordHash()->HashPassword($password);
        \EvolutionCMS\Models\User::where('username', $username)->update($field);

    }
}

if (!function_exists('saveUserGroupAccessPermissons')) {
    /**
     * saves module user group access
     */
    function saveUserGroupAccessPermissons()
    {
        $modx = evolutionCMS();
        global $id, $newid;
        global $use_udperms;

        if ($newid) {
            $id = $newid;
        }
        $usrgroups = $_POST['usrgroups'];

        // check for permission update access
        if ($use_udperms == 1) {
            // delete old permissions on the module
            \EvolutionCMS\Models\SiteModuleAccess::where('module', $id)->delete();

            if (is_array($usrgroups)) {
                foreach ($usrgroups as $value) {
                    \EvolutionCMS\Models\SiteModuleAccess::create(array(
                        'module' => (int)$id,
                        'usergroup' => stripslashes($value),
                    ));

                }
            }
        }
    }
}

if (!function_exists('saveEventListeners')) {
# Save Plugin Event Listeners
    function saveEventListeners($id, $sysevents, $mode)
    {
        // save selected system events
        $formEventList = array();
        foreach ($sysevents as $evtId) {
            if (!preg_match('@^[1-9][0-9]*$@', $evtId)) {
                $evtId = getEventIdByName($evtId);
            }
            if ($mode == '101') {
                $prevPriority = \EvolutionCMS\Models\SitePluginEvent::query()->where('evtid', $evtId)->max('priority');
            } else {
                $prevPriority = \EvolutionCMS\Models\SitePluginEvent::query()->where('evtid', $evtId)
                    ->where('pluginid', $id)->max('priority');
            }
            if ($mode == '101') {
                $priority = isset($prevPriority) ? $prevPriority + 1 : 1;
            } else {
                $priority = isset($prevPriority) ? $prevPriority : 1;
            }
            $priority = (int)$priority;
            $formEventList[] = array('pluginid' => $id, 'evtid' => $evtId, 'priority' => $priority);
        }

        $evtids = array();
        foreach ($formEventList as $eventInfo) {
            \EvolutionCMS\Models\SitePluginEvent::query()->updateOrCreate(['pluginid' => $eventInfo['pluginid'], 'evtid' => $eventInfo['evtid']], ['priority' => $eventInfo['priority']]);
            $evtids[] = $eventInfo['evtid'];
        }
        $pluginEvents = \EvolutionCMS\Models\SitePluginEvent::query()->where('pluginid', $id)->get();

        $del = array();
        foreach ($pluginEvents->toArray() as $row) {
            if (!in_array($row['evtid'], $evtids)) {
                $del[] = $row['evtid'];
            }
        }

        if (empty($del)) {
            return;
        }
        \EvolutionCMS\Models\SitePluginEvent::query()->where('pluginid', $id)->whereIn('evtid', $del)->delete();

    }
}

if (!function_exists('getEventIdByName')) {
    /**
     * @param string $name
     * @return string|int
     */
    function getEventIdByName($name)
    {
        $modx = evolutionCMS();
        static $eventIds = array();

        if (isset($eventIds[$name])) {
            return $eventIds[$name];
        }
        $eventIds = \EvolutionCMS\Models\SystemEventname::query()->pluck('id', 'name')->toArray();

        return $eventIds[$name];
    }
}

if (!function_exists('saveTemplateAccess')) {
    /**
     * @param int $id
     */
    function saveTemplateAccess($id)
    {
        $modx = evolutionCMS();
        if ($_POST['tvsDirty'] == 1) {
            $newAssignedTvs = isset($_POST['assignedTv']) ? $_POST['assignedTv'] : '';

            // Preserve rankings of already assigned TVs
            $templates = SiteTmplvarTemplate::query()->where('templateid', $id)->get();
            $ranksArr = array();
            $highest = 0;
            foreach ($templates->toArray() as $row) {
                $ranksArr[$row['tmplvarid']] = $row['rank'];
                $highest = $highest < $row['rank'] ? $row['rank'] : $highest;
            };
            SiteTmplvarTemplate::query()->where('templateid', $id)->delete();

            if (empty($newAssignedTvs)) {
                return;
            }
            foreach ($newAssignedTvs as $tvid) {
                if (!$id || !$tvid) {
                    continue;
                }    // Dont link zeros
                SiteTmplvarTemplate::create(array(
                    'templateid' => $id,
                    'tmplvarid' => $tvid,
                    'rank' => isset($ranksArr[$tvid]) ? $ranksArr[$tvid] : $highest += 1 // append TVs to rank
                ));
            }
        }
    }
}

if (!function_exists('saveTemplateVarAccess')) {
    /**
     * @return void
     */
    function saveTemplateVarAccess($id)
    {
        $modx = evolutionCMS();
        $templates = isset($_POST['template']) ? $_POST['template'] : []; // get muli-templates based on S.BRENNAN mod

        $siteTmlvarTemplates = EvolutionCMS\Models\SiteTmplvarTemplate::where('tmplvarid', '=', $id)->get();

        $getRankArray = $siteTmlvarTemplates->pluck('rank', 'templateid')->toArray();
        /*foreach ($siteTmlvarTemplates as $siteTmlvarTemplate) {
            $getRankArray[$siteTmlvarTemplate->templateid] = $siteTmlvarTemplate->rank;
        }*/

        EvolutionCMS\Models\SiteTmplvarTemplate::where('tmplvarid', '=', $id)->delete();
        if (!$templates) {
            return;
        }
        foreach ($templates as $i => $iValue) {
            $field = [
                'tmplvarid' => $id,
                'templateid' => $templates[$i],
                'rank' => get_by_key($getRankArray, $iValue, 0)
            ];
            EvolutionCMS\Models\SiteTmplvarTemplate::create($field);
        }
    }
}

if (!function_exists('saveDocumentAccessPermissons')) {
    function saveDocumentAccessPermissons($id)
    {
        $modx = evolutionCMS();

        $docgroups = isset($_POST['docgroups']) ? $_POST['docgroups'] : '';

        // check for permission update access
        if ($modx->getConfig('use_udperms') != 1) {
            return;
        }

        // delete old permissions on the tv
        EvolutionCMS\Models\SiteTmplvarAccess::where('tmplvarid', '=', $id)->delete();
        if (is_array($docgroups)) {
            foreach ($docgroups as $value) {
                $field = ['tmplvarid' => $id, 'documentgroup' => stripslashes($value)];
                EvolutionCMS\Models\SiteTmplvarAccess::create($field);
            }
        }
    }
}

if (!function_exists('sendMailMessageForUser')) {
    /**
     * Send an email to the user
     *
     * @param string $email
     * @param string $uid
     * @param string $pwd
     * @param string $ufn
     */
    function sendMailMessageForUser($email, $uid, $pwd, $ufn, $message, $url)
    {
        $modx = evolutionCMS();
        global $_lang;
        global $emailsubject, $emailsender;
        $message = sprintf($message, $uid, $pwd); // use old method
        // replace placeholders
        $message = str_replace(
            array('[+uid+]', '[+pwd+]', '[+ufn+]', '[+sname+]', '[+saddr+]', '[+semail+]', '[+surl+]')
            , array($uid, $pwd, $ufn, $modx->getPhpCompat()->entities($modx->getConfig('site_name')), $emailsender, $emailsender, $url)
            , $message
        );

        $param = array();
        $param['from'] = sprintf('%s<%s>', $modx->getConfig('site_name'), $emailsender);
        $param['subject'] = $emailsubject;
        $param['body'] = $message;
        $param['to'] = $email;
        $param['type'] = 'text';
        if ($modx->sendmail($param)) {
            return;
        }
        $modx->getManagerApi()->saveFormValues();
        $modx->messageQuit(sprintf('%s - %s', $email, $_lang['error_sending_email']));
    }
}


if (!function_exists('webAlertAndQuit')) {
    /**
     * Web alert -  sends an alert to web browser
     *
     * @param $msg
     * @param $action int
     * @param $id int|bool
     */
    function webAlertAndQuit($msg, $action, $id = false)
    {
        $modx = EvolutionCMS();
        $mode = $_POST['mode'];
        $modx->getManagerApi()->saveFormValues($mode);
        $url = 'index.php?a=' . $action;
        if ($id != false) {
            $url .= '&id=' . $id;
        }
        $modx->webAlertAndQuit($msg, $url);
    }
}