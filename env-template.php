<?php

// Copy to env.php (which is in .gitignore) for local settings. On a server set these
// as environment variables instead. Anything left unset falls back to the defaults in
// config.inc.php.

putenv('ELASTIC_URL=http://127.0.0.1:9200');
putenv('ELASTIC_INDEX=bhl');
putenv('ELASTIC_USERNAME=');
putenv('ELASTIC_PASSWORD=');

?>
