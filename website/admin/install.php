<?php
// The previous web installer could reset admin accounts without authentication.
// Initial installation must be performed offline by a trusted administrator.
http_response_code(404);
exit;
