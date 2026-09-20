<?php
/**
 * Legacy walk-in page — now a redirect.
 *
 * The original version of this file was written against an earlier schema that no
 * longer exists: it queried a `dentists` table (there is none — dentists live in
 * `employees`) and wrote to users.password/users.role, patients.phone,
 * patients.is_walk_in and appointments.appointment_time, none of which are real
 * columns. Every request to it failed. Nothing linked here either; the receptionist
 * sidebar never included it.
 *
 * The walk-in workflow is fully implemented elsewhere against the current schema:
 *   - register a new walk-in patient -> patients.php  (registered_via = 'walk-in')
 *   - book the walk-in appointment   -> appointments.php?view=book
 *
 * The file is kept as a redirect so any old bookmark still lands somewhere useful.
 */

require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Receptionist']);

header('Location: appointments.php?view=book');
exit;
