<?php

/**
 * SCRIPT PARAMETRIZATION
 */
const DEBUG = false;
const TEST_DIR = __DIR__;

error_reporting(E_ALL & ~E_STRICT);

/**
 * FUNCTIONS
 */

function is_posix() {
    return is_fun('posix_getuid') && is_fun('posix_getgid') && is_fun('posix_getpwuid');
}

function fperms($file) {
    return substr(sprintf("%o", fileperms($file)), -4);
}

function has_write_perm_to($file, $who) {
    $perm_classes = array('u' => 0200, 'g' => 0020, 'o' => 0002);
    if (!in_array($who, array_keys($perm_classes))) {
        errf("Invalid permissions class requested: '%s'. Choose one of [u,g,o].", $who);
    }
    $perms = intval(fperms($file), 8);
    return ($perms & $perm_classes[$who]) !== 0;
}

function list_dir($dir) {
    if ($dir[strlen($dir) - 1] === '/') $dir = substr($dir, 0, -1);
    return array_map(
        function ($item) use ($dir) {
            return $item === '.' ? ($dir) : ($dir . '/' . $item);
        },
        array_filter(
            scandir($dir),
            function ($item) {
                return $item !== '..';
            }
        )
    );
}

function is_fun($fname) {
    return function_exists($fname) && is_callable($fname);
}

function can_write_to($dir) {
    $test_file = $dir . '/__writable.tmp';
    if (@touch($test_file)) {
        debugf("File '%s' (ugo %s) was created.", $test_file, fperms($test_file));
        debugf("Dir '%s' (ugo %s) IS writable by webserver.", $dir, fperms($dir));

        if (!unlink($test_file)) {
            printf("[-] File '%s' could not be deleted. Please remove it manually.", $test_file);
            exit(1);
        } else {
            debugf("Test file '%s' was removed.", $test_file);
        }

        return true;
    } else {
        debugf("Dir '%s' (ugo %s) is NOT writable by webserver.", $dir, fperms($dir));
        return false;
    }
}

function debugf($fmt, ...$args) {
    if (DEBUG) printf("[#] " . $fmt . "\n", ...$args);
}

function logf($fmt, ...$args) {
    printf("[+] " . $fmt . "\n", ...$args);
}

function errf($fmt, ...$args) {
    printf("[-] " . $fmt . "\n", ...$args);
    exit(1);
}

/**
 * SANITY CHECKS
 */

if (!file_exists(TEST_DIR) || !is_dir(TEST_DIR)) {
    errf("Dir '%s' does not exist or was not a dir in the first place.");
}

/**
 * TEST EXECUTION
 */

logf("Running PHP %s (%s) on %s",
    phpversion(),
    is_fun('php_sapi_name') ? php_sapi_name() : "???",
    is_fun('posix_uname') ? print_r(posix_uname(), true) : "???"
);
print "\n";

logf("Let's check some functions first:");
$funs = ["chmod", "chown", "chgrp", "eval", "assert", "exec", "putenv", "passthru", "system", "shell_exec", "popen", "proc_open", "pcntl_exec", "fstat"];
foreach ($funs as $fun) {
    logf("Is '%s' available? %s", $fun, is_fun($fun) ? "T" : "F");
}
print "\n";

logf("Script permissions: %s", fperms(__FILE__));
print "\n";

logf("Open basedir: '%s'", ini_get('open_basedir'));
print "\n";

$BASEDIRS = explode(PATH_SEPARATOR, ini_get('open_basedir'));
if (count($BASEDIRS) > 0 && strlen($BASEDIRS[0]) > 0) {
    logf("Open basedir permissions:");
    foreach ($BASEDIRS as $i => $BASEDIR) {
        $BASEDIRS[$i] = list_dir($BASEDIR);
        foreach ($BASEDIRS[$i] as $item) {
            logf("'%s'\t%s\t%s", $item, fileowner($item), fperms($item));
        }
    }
    print "\n";
}

logf("Starting server process owner detection:");
$uid_match = FALSE;

if (is_posix()) {
    logf("Using POSIX functions to compare file and process owner.");

    $uid = posix_getuid();
    $gid = posix_getgid();
    $euid = is_fun('posix_geteuid') ? posix_geteuid() : $uid;
    $egid = is_fun('posix_getegid') ? posix_getegid() : $gid;

    logf("Running as: %s", print_r(posix_getpwuid($uid), true));

    if ($uid !== $euid) {
        logf("Effective UID: %s", print_r(posix_getpwuid($euid), true));
    }

    if ($uid !== $gid) {
        logf("GID: %s", print_r(posix_getpwuid($gid), true));
    }

    if ($gid != $egid) {
        logf("Effective GID: %s", print_r(posix_getpwuid($egid)), true);
    }

    $fuid = fileowner(__FILE__);
    logf("Script owner: %s", print_r(posix_getpwuid($fuid), true));
    print "\n";

    $uid_match = $uid === $fuid || $euid === $fuid;

} else if (is_fun('chmod')) {
    logf("POSIX functions are not available -> falling back to a chmod test.");

    $perms = 0777;
    $perms_backup = intval(fperms(TEST_DIR), 8);
    debugf("Trying to `chmod %o %s`.", $perms, TEST_DIR);
    if (@chmod(TEST_DIR, $perms)) {
        clearstatcache(true, TEST_DIR);
        debugf("Chmod successful.");
        $uid_match = true;

        debugf("Reverting original permissions (%o) on dir '%s'.", $perms_backup, TEST_DIR);
        if (!chmod(TEST_DIR, $perms_backup)) {
            errf("Could not `chmod %s %s`.", $perms_backup, TEST_DIR);
        }
        clearstatcache(true, TEST_DIR);
    } else {
        debugf("Could not `chmod %o %s`.", $perms, TEST_DIR);
    }
} else {
    logf("Neither POSIX functions nor chmod are available -> falling back to a write test.");

    // OK, who can write here?
    if (!has_write_perm_to(TEST_DIR, 'g') && !has_write_perm_to(TEST_DIR, 'o')) {
        debugf("Dir '%s' is NOT writable for group and world.", TEST_DIR);
        // So only owner can probably write here. But mainly, can we?
        $uid_match = can_write_to(TEST_DIR);
    } else {
        errf("Cannot run write test in a globally writable dir. Make '%s' writable for owner only and try again.", TEST_DIR);
    }
}

print "\n";
if ($uid_match) {
    logf("Oh no. This looks bad :( File owner == Process owner");
} else {
    logf("Test passed.");
}