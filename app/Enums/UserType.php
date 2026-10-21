<?php

namespace App\Enums;

/**
 * Account types (schema v2: user.type). The patient app only ever works with
 * `Patient`. The others exist so the portal can plug in later without a migration
 * of this column. Add `IndProvider` here when independent providers are built.
 */
enum UserType: string
{
    case Patient = 'patient';
    case OrgAdmin = 'org_admin';
    case OrgStaff = 'org_staff';
    case PlatformAdmin = 'platform_admin';
}
