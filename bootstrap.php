<?php
/**
 * Early phase application bootstrap hook. Constructing the Mini class will set the immutable
 * singleton `mini\Mini::$mini`.
 */
new \mini\Mini();
