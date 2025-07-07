<?php
/* <one line to give the program's name and a brief idea of what it does.>
 * Copyright (C) 2015 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *  \file       lib/savorders.lib.php
 *  \ingroup    savorders
 *  \brief      This file is an example module library
 *              Put some comments here
 */

function savordersPrepareHead()
{
    global $langs, $conf, $db;
    $langs->load('savorders@savorders');
    $h = 0;
    $head = array();
    
    dol_include_once('/savorders/class/savorders.class.php');

    $head[$h][0] = dol_buildpath("/savorders/admin/admin.php", 1);
    $head[$h][1] = $langs->trans("General");
    $head[$h][2] = 'general';
    $h++;
       
    return $head;
}