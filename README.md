SimpleHtmlDom
=============

Modified version of http://sourceforge.net/projects/simplehtmldom/ with cache support

####Cache info
The cache writes the content of the website into a text file in the cache folder. The file is named by the md5 hash of the url.
When the file is older than the default cache duration (24h), it will be overwritten.

####Simple cache example
In this example the cache folder is set to "mycachefolder", the default value is "cache".

The methode fileGetHtml has a new param, which is called $hours, this sets the cache duration.
In further version the param will be changed to a static variable.

```
<?php
use ThauEx\SimpleHtmlDom\SHD;

SHD::$fileCacheDir = "mycachefolder";
$html = SHD::fileGetHtml("http://google.de/");
?>
```