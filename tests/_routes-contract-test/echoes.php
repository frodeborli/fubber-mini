<?php
// Invalid: direct output from a route file must throw
echo "this is not allowed";
return new \mini\Http\Message\Response('unreachable');
