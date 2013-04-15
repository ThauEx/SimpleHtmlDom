<?php
namespace ThauEx\SimpleHtmlDom;
/**
 * Website: http://sourceforge.net/projects/simplehtmldom/
 * Acknowledge: Jose Solorzano (https://sourceforge.net/projects/php-html/)
 * Contributions by:
 *     Yousuke Kumakura (Attribute filters)
 *     Vadim Voituk (Negative indexes supports of "find" method)
 *     Antcs (Constructor with automatically load contents either text or file/url)
 *
 * all affected sections have comments starting with "PaperG"
 *
 * Paperg - Added case insensitive testing of the value of the selector.
 * Paperg - Added tag_start for the starting index of tags - NOTE: This works but not accurately.
 *  This tag_start gets counted AFTER \r\n have been crushed out, and after the remove_noice calls so it will not reflect the REAL position of the tag in the source,
 *  it will almost always be smaller by some amount.
 *  We use this to determine how far into the file the tag in question is.  This "percentage will never be accurate as the $dom->size is the "real" number of bytes the dom was created from.
 *  but for most purposes, it's a really good estimation.
 * Paperg - Added the forceTagsClosed to the dom constructor.  Forcing tags closed is great for malformed html, but it CAN lead to parsing errors.
 * Allow the user to tell us how much they trust the html.
 * Paperg add the text and plaintext to the selectors for the find syntax.  plaintext implies text in the innertext of a node.  text implies that the tag is a text node.
 * This allows for us to find tags based on the text they contain.
 * Create find_ancestor_tag to see if a tag is - at any level - inside of another specific tag.
 * Paperg: added parse_charset so that we know about the character set of the source document.
 *  NOTE:  If the user's system has a routine called get_last_retrieve_url_contents_content_type availalbe, we will assume it's returning the content-type header from the
 *  last transfer or curl_exec, and we will parse that and use it in preference to any other method of charset detection.
 *
 * Found infinite loop in the case of broken html in restore_noise.  Rewrote to protect from that.
 * PaperG (John Schlick) Added get_display_size for "IMG" tags.
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @author S.C. Chen <me578022@gmail.com>
 * @author John Schlick
 * @author Rus Carroll
 * @version 1.5 ($Rev: 196 $)
 * @package PlaceLocalInclude
 * @subpackage simple_html_dom
 */
class SHD
{
    /**
     * All of the Defines for the classes below.
     * @author S.C. Chen <me578022@gmail.com>
     */
    const HDOM_TYPE_ELEMENT         = 1;
    const HDOM_TYPE_COMMENT         = 2;
    const HDOM_TYPE_TEXT            = 3;
    const HDOM_TYPE_ENDTAG          = 4;
    const HDOM_TYPE_ROOT            = 5;
    const HDOM_TYPE_UNKNOWN         = 6;
    const HDOM_QUOTE_DOUBLE         = 0;
    const HDOM_QUOTE_SINGLE         = 1;
    const HDOM_QUOTE_NO             = 3;
    const HDOM_INFO_BEGIN           = 0;
    const HDOM_INFO_END             = 1;
    const HDOM_INFO_QUOTE           = 2;
    const HDOM_INFO_SPACE           = 3;
    const HDOM_INFO_TEXT            = 4;
    const HDOM_INFO_INNER           = 5;
    const HDOM_INFO_OUTER           = 6;
    const HDOM_INFO_ENDSPACE        = 7;
    const DEFAULT_TARGET_CHARSET    = 'UTF-8';
    const DEFAULT_BR_TEXT           = "\r\n";
    const DEFAULT_SPAN_TEXT         = " ";
    const MAX_FILE_SIZE             = 600000;
    public static $fileCacheDir     = 'cache';
    // helper functions
    // -----------------------------------------------------------------------------
    // get html dom from file
    // $maxlen is defined in the code as PHP_STREAM_COPY_ALL which is defined as -1.
    public static function fileGetHtml($url, $useIncludePath = false, $context=null, $offset = -1, $maxLen=-1, $lowercase = true, $forceTagsClosed=true, $targetCharset =self::DEFAULT_TARGET_CHARSET, $stripRN=true, $defaultBRText=self::DEFAULT_BR_TEXT, $defaultSpanText=self::DEFAULT_SPAN_TEXT, $hours = 24)
    {
        // We DO force the tags to be terminated.
        $dom = new SimpleHtmlDom(null, $lowercase, $forceTagsClosed, $targetCharset, $stripRN, $defaultBRText, $defaultSpanText);
        // For sourceforge users: uncomment the next line and comment the retreive_url_contents line 2 lines down if it is not already done.
        #$contents = file_get_contents($url, $useIncludePath, $context, $offset);
        $contents = self::getContent($url, $useIncludePath, $context, $offset, $hours);
        // Paperg - use our own mechanism for getting the contents as we want to control the timeout.
        //$contents = retrieve_url_contents($url);
        if (empty($contents) || strlen($contents) > self::MAX_FILE_SIZE)
        {
            return false;
        }
        // The second parameter can force the selectors to all be lowercase.
        $dom->load($contents, $lowercase, $stripRN);
        return $dom;
    }

    public static function getContent($url, $useIncludePath = false, $context=null, $offset = -1, $hours = 24)
    {
        $file = self::$fileCacheDir . DIRECTORY_SEPARATOR . md5($url);

        if(file_exists($file))
        {
            $currentTime = time();
            $expireTime = $hours * 60 * 60;
            $file_time = filemtime($file);

            if ($currentTime - $expireTime < $file_time)
            {
                return file_get_contents($file);
            }
        }

        $content = file_get_contents($url, $useIncludePath, $context, $offset);
        $content .= '<!-- cached: ' . time() . ' -->';
        file_put_contents($file, $content);

        return $content;
    }

    // get html dom from string
    public static function strGetHtml($str, $lowercase=true, $forceTagsClosed=true, $targetCharset = self::DEFAULT_TARGET_CHARSET, $stripRN=true, $defaultBRText=self::DEFAULT_BR_TEXT, $defaultSpanText=self::DEFAULT_SPAN_TEXT)
    {
        $dom = new SimpleHtmlDom(null, $lowercase, $forceTagsClosed, $targetCharset, $stripRN, $defaultBRText, $defaultSpanText);
        if (empty($str) || strlen($str) > self::MAX_FILE_SIZE)
        {
            $dom->clear();
            return false;
        }
        $dom->load($str, $lowercase, $stripRN);
        return $dom;
    }

    // dump html dom tree
    public static function dumpHtmlTree($node, $showAttr=true, $deep=0)
    {
        $node->dump($node);
    }
}

