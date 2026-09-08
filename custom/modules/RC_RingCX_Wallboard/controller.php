<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

#[\AllowDynamicProperties]
class RC_RingCX_WallboardController extends SugarController
{
    // Base SugarController remaps the default "index" action to "listview" (which
    // needs a SugarBean and would otherwise show a hard "no access" for this
    // bean-less redirect module regardless of ACL/admin status). Disable that remap
    // so action_index() below actually runs.
    protected $action_remap = array();

    protected function action_index()
    {
        header('Location: /legacy/index.php?entryPoint=RC_RingCX_Wallboard');
        exit;
    }
}
