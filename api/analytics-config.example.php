<?php
// Copy this file to analytics-config.php, then fill in the GA4 property ID
// and the complete service-account JSON downloaded from Google Cloud.
return [
    'property_id' => 'YOUR_GA4_PROPERTY_ID',
    'service_account' => [
        'client_email' => 'analytics-reader@your-project.iam.gserviceaccount.com',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nYOUR_PRIVATE_KEY\n-----END PRIVATE KEY-----\n",
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ],
];
