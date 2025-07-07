<?php
/* Copyright (C) 2024 Your Company
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*/

/**
 * \file        htdocs/custom/savtab/class/actions_savtab.class.php
 * \ingroup     savtab
 * \brief       Hook class for SavTAB module to add SAV tab to order card
 */

/**
 * Class ActionsSavTAB
 * Hooks into Dolibarr to extend sales order functionality with an SAV tab.
 */
class ActionsSavTAB
{
    /**
     * @var DoliDB Database handler.
     */
    public $db;

    /**
     * @var string Error code (or message)
     */
    public $error = '';

    /**
     * @var array Errors
     */
    public $errors = array();

    /**
     * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
     */
    public $results = array();

    /**
     * @var string String displayed by executeHook() immediately after return
     */
    public $resprints;

    /**
     * Constructor
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Adds more action buttons to the order card (currently unused).
     * @param array         $parameters     Hook metadata (context, etc...)
     * @param CommonObject  $object         The object to process
     * @param string        $action         Current action (create, edit, null)
     * @param HookManager   $hookmanager    Hook manager
     * @return int                          0 on success, -1 on error
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $user, $langs;

        $error = 0; // Error counter

        if (in_array($parameters['currentcontext'], array('ordercard'))) {
            // Placeholder for future SAV-specific buttons
        }

        if (!$error) {
            $this->results = array();
            $this->resprints = '';
            return 0;
        } else {
            $this->errors[] = 'Error message';
            return -1;
        }
    }

    /**
     * Adds the SAV tab to the order card’s tab head.
     * @param array         $parameters     Hook metadata (context, etc...)
     * @param CommonObject  $object         The order object
     * @param string        $action         Current action (create, edit, null)
     * @param HookManager   $hookmanager    Hook manager
     * @return int                          0 on success
     */
// ... inside class ActionsSavTAB ...

public function completeTabsHead(&$parameters, &$object, &$action, $hookmanager)
{
    // ---- TEMPORARY DEBUG LINE ----
    die("DEBUG: The completeTabsHead hook IS being called!");

    global $langs, $conf, $user;

if ($hookmanager->context == 'ordercard' && $object->element == 'commande') {
            $langs->load("savtab@savtab");

            // Add SAV tab to the head if not already present
            $head = &$parameters['head'];
            if (is_array($head)) {
                $tabExists = false;
                foreach ($head as $tab) {
                    if ($tab[2] == 'sav') {
                        $tabExists = true;
                        break;
                    }
                }
                if (!$tabExists) {
                    $h = count($head);
                    $head[$h][0] = dol_buildpath('/custom/savtab/sav_tab.php', 1) . '?id=' . $object->id;
                    $head[$h][1] = $langs->trans("SAV");
                    $head[$h][2] = 'sav';
                }
            }
        }

        return 0;
    }
} // ---- FIX: Removed an extra '}' here that would cause a parse error.