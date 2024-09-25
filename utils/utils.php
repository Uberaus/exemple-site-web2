<?php

function highlight_array($array, $name = 'var')
{
  highlight_string("<?php\n$name =\n" . var_export($array, true) . ";\n?>");
}

function out($text)
{
  echo htmlspecialchars($text);
}
