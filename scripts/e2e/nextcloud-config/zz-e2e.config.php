<?php

$CONFIG = [
    // Disable the App Store during bootstrap so occ does not require
    // write access to the shipped apps directory on CI runners.
    'appstoreenabled' => false,
];
