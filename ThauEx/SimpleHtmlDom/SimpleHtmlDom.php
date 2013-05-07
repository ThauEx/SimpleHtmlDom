<?php
namespace ThauEx\SimpleHtmlDom;

/**
 * simple html dom parser
 * Paperg - in the find routine: allow us to specify that we want case insensitive testing of the value of the selector.
 * Paperg - change $size from protected to public so we can easily access it
 * Paperg - added ForceTagsClosed in the constructor which tells us whether we trust the html or not.  Default is to NOT trust it.
 *
 * @package PlaceLocalInclude
 */
class SimpleHtmlDom
{
    public $root = null;
    public $nodes = array();
    public $callback = null;
    public $lowercase = false;
    // Used to keep track of how large the text was when we started.
    public $originalSize;
    public $size;
    protected $pos;
    protected $doc;
    protected $char;
    protected $cursor;
    protected $parent;
    protected $noise = array();
    protected $tokenBlank = " \t\r\n";
    protected $tokenEqual = ' =/>';
    protected $tokenSlash = " />\r\n\t";
    protected $token_attr = ' >';
    // Note that this is referenced by a child node, and so it needs to be public for that node to see this information.
    public $_charset = '';
    public $_targetCharset = '';
    protected $defaultBrText = "";
    public $defaultSpanText = "";

    // use isset instead of in_array, performance boost about 30%...
    protected $selfClosingTags = array('img'=>1, 'br'=>1, 'input'=>1, 'meta'=>1, 'link'=>1, 'hr'=>1, 'base'=>1, 'embed'=>1, 'spacer'=>1);
    protected $blockTags = array('root'=>1, 'body'=>1, 'form'=>1, 'div'=>1, 'span'=>1, 'table'=>1);
    // Known sourceforge issue #2977341
    // B tags that are not closed cause us to return everything to the end of the document.
    protected $optionalClosingTags = array(
        'tr'=>array('tr'=>1, 'td'=>1, 'th'=>1),
        'th'=>array('th'=>1),
        'td'=>array('td'=>1),
        'li'=>array('li'=>1),
        'dt'=>array('dt'=>1, 'dd'=>1),
        'dd'=>array('dd'=>1, 'dt'=>1),
        'dl'=>array('dd'=>1, 'dt'=>1),
        'p'=>array('p'=>1),
        'nobr'=>array('nobr'=>1),
        'b'=>array('b'=>1),
        'option'=>array('option'=>1),
    );

    function __construct($str=null, $lowercase=true, $forceTagsClosed=true, $targetCharset=SHD::DEFAULT_TARGET_CHARSET, $stripRN=true, $defaultBRText=SHD::DEFAULT_BR_TEXT, $defaultSpanText=SHD::DEFAULT_SPAN_TEXT)
    {
        if ($str)
        {
            if (preg_match("/^http:\/\//i",$str) || is_file($str))
            {
                $this->loadFile($str);
            }
            else
            {
                $this->load($str, $lowercase, $stripRN, $defaultBRText, $defaultSpanText);
            }
        }
        // Forcing tags to be closed implies that we don't trust the html, but it can lead to parsing errors if we SHOULD trust the html.
        if (!$forceTagsClosed) {
            $this->optionalClosingArray=array();
        }
        $this->_targetCharset = $targetCharset;
    }

    function __destruct()
    {
        $this->clear();
    }

    // load html from string
    function load($str, $lowercase=true, $stripRN=true, $defaultBRText=SHD::DEFAULT_BR_TEXT, $defaultSpanText=SHD::DEFAULT_SPAN_TEXT)
    {
        global $debugObject;

        // prepare
        $this->prepare($str, $lowercase, $stripRN, $defaultBRText, $defaultSpanText);
        // strip out comments
        $this->removeNoise("'<!--(.*?)-->'is");
        // strip out cdata
        $this->removeNoise("'<!\[CDATA\[(.*?)\]\]>'is", true);
        // Per sourceforge http://sourceforge.net/tracker/?func=detail&aid=2949097&group_id=218559&atid=1044037
        // Script tags removal now preceeds style tag removal.
        // strip out <script> tags
        $this->removeNoise("'<\s*script[^>]*[^/]>(.*?)<\s*/\s*script\s*>'is");
        $this->removeNoise("'<\s*script\s*>(.*?)<\s*/\s*script\s*>'is");
        // strip out <style> tags
        $this->removeNoise("'<\s*style[^>]*[^/]>(.*?)<\s*/\s*style\s*>'is");
        $this->removeNoise("'<\s*style\s*>(.*?)<\s*/\s*style\s*>'is");
        // strip out preformatted tags
        $this->removeNoise("'<\s*(?:code)[^>]*>(.*?)<\s*/\s*(?:code)\s*>'is");
        // strip out server side scripts
        $this->removeNoise("'(<\?)(.*?)(\?>)'s", true);
        // strip smarty scripts
        $this->removeNoise("'(\{\w)(.*?)(\})'s", true);

        // parsing
        while ($this->parse());
        // end
        $this->root->_[SHD::HDOM_INFO_END] = $this->cursor;
        $this->parseCharset();

        // make load function chainable
        return $this;

    }

    // load html from file
    function loadFile()
    {
        $args = func_get_args();
        $this->load(call_user_func_array('file_get_contents', $args), true);
        // Throw an error if we can't properly load the dom.
        if (($error=error_get_last())!==null) {
            $this->clear();
            return false;
        }
    }

    // set callback function
    function setCallback($function_name)
    {
        $this->callback = $function_name;
    }

    // remove callback function
    function removeCallback()
    {
        $this->callback = null;
    }

    // save dom as string
    function save($filepath='')
    {
        $ret = $this->root->innertext();
        if ($filepath!=='') file_put_contents($filepath, $ret, LOCK_EX);
        return $ret;
    }

    // find dom node by css selector
    // Paperg - allow us to specify that we want case insensitive testing of the value of the selector.
    function find($selector, $idx=null, $lowercase=false)
    {
        return $this->root->find($selector, $idx, $lowercase);
    }

    // clean up memory due to php5 circular references memory leak...
    function clear()
    {
        foreach ($this->nodes as $n) {$n->clear(); $n = null;}
        // This add next line is documented in the sourceforge repository. 2977248 as a fix for ongoing memory leaks that occur even with the use of clear.
        if (isset($this->children)) foreach ($this->children as $n) {$n->clear(); $n = null;}
        if (isset($this->parent)) {$this->parent->clear(); unset($this->parent);}
        if (isset($this->root)) {$this->root->clear(); unset($this->root);}
        unset($this->doc);
        unset($this->noise);
    }

    function dump($show_attr=true)
    {
        $this->root->dump($show_attr);
    }

    // prepare HTML data and init everything
    protected function prepare($str, $lowercase=true, $stripRN=true, $defaultBRText=SHD::DEFAULT_BR_TEXT, $defaultSpanText=SHD::DEFAULT_SPAN_TEXT)
    {
        $this->clear();

        // set the length of content before we do anything to it.
        $this->size = strlen($str);
        // Save the original size of the html that we got in.  It might be useful to someone.
        $this->originalSize = $this->size;

        //before we save the string as the doc...  strip out the \r \n's if we are told to.
        if ($stripRN) {
            $str = str_replace("\r", " ", $str);
            $str = str_replace("\n", " ", $str);

            // set the length of content since we have changed it.
            $this->size = strlen($str);
        }

        $this->doc = $str;
        $this->pos = 0;
        $this->cursor = 1;
        $this->noise = array();
        $this->nodes = array();
        $this->lowercase = $lowercase;
        $this->defaultBrText = $defaultBRText;
        $this->defaultSpanText = $defaultSpanText;
        $this->root = new SimpleHtmlDomNode($this);
        $this->root->tag = 'root';
        $this->root->_[SHD::HDOM_INFO_BEGIN] = -1;
        $this->root->nodetype = SHD::HDOM_TYPE_ROOT;
        $this->parent = $this->root;
        if ($this->size>0) $this->char = $this->doc[0];
    }

    // parse html content
    protected function parse()
    {
        if (($s = $this->copyUntilChar('<'))==='')
        {
            return $this->readTag();
        }

        // text
        $node = new SimpleHtmlDomNode($this);
        ++$this->cursor;
        $node->_[SHD::HDOM_INFO_TEXT] = $s;
        $this->linkNodes($node, false);
        return true;
    }

    // PAPERG - dkchou - added this to try to identify the character set of the page we have just parsed so we know better how to spit it out later.
    // NOTE:  IF you provide a routine called getLastRetrieveUrlContentsContentType which returns the CURLINFO_CONTENT_TYPE from the last curl_exec
    // (or the content_type header from the last transfer), we will parse THAT, and if a charset is specified, we will use it over any other mechanism.
    protected function parseCharset()
    {
        global $debugObject;

        $charset = null;

        if (function_exists('getLastRetrieveUrlContentsContentType'))
        {
            $contentTypeHeader = getLastRetrieveUrlContentsContentType();
            $success = preg_match('/charset=(.+)/', $contentTypeHeader, $matches);
            if ($success)
            {
                $charset = $matches[1];
                if (is_object($debugObject)) {$debugObject->debugLog(2, 'header content-type found charset of: ' . $charset);}
            }

        }

        if (empty($charset))
        {
            $el = $this->root->find('meta[http-equiv=Content-Type]',0);
            if (!empty($el))
            {
                $fullvalue = $el->content;
                if (is_object($debugObject)) {$debugObject->debugLog(2, 'meta content-type tag found' . $fullvalue);}

                if (!empty($fullvalue))
                {
                    $success = preg_match('/charset=(.+)/', $fullvalue, $matches);
                    if ($success)
                    {
                        $charset = $matches[1];
                    }
                    else
                    {
                        // If there is a meta tag, and they don't specify the character set, research says that it's typically ISO-8859-1
                        if (is_object($debugObject)) {$debugObject->debugLog(2, 'meta content-type tag couldn\'t be parsed. using iso-8859 default.');}
                        $charset = 'ISO-8859-1';
                    }
                }
            }
        }

        // If we couldn't find a charset above, then lets try to detect one based on the text we got...
        if (empty($charset))
        {
            // Have php try to detect the encoding from the text given to us.
            $charset = mb_detect_encoding($this->root->plaintext . "ascii", $encoding_list = array( "UTF-8", "CP1252" ) );
            if (is_object($debugObject)) {$debugObject->debugLog(2, 'mb_detect found: ' . $charset);}

            // and if this doesn't work...  then we need to just wrongheadedly assume it's UTF-8 so that we can move on - cause this will usually give us most of what we need...
            if ($charset === false)
            {
                if (is_object($debugObject)) {$debugObject->debugLog(2, 'since mb_detect failed - using default of utf-8');}
                $charset = 'UTF-8';
            }
        }

        // Since CP1252 is a superset, if we get one of it's subsets, we want it instead.
        if ((strtolower($charset) == strtolower('ISO-8859-1')) || (strtolower($charset) == strtolower('Latin1')) || (strtolower($charset) == strtolower('Latin-1')))
        {
            if (is_object($debugObject)) {$debugObject->debugLog(2, 'replacing ' . $charset . ' with CP1252 as its a superset');}
            $charset = 'CP1252';
        }

        if (is_object($debugObject)) {$debugObject->debugLog(1, 'EXIT - ' . $charset);}

        return $this->_charset = $charset;
    }

    // read tag info
    protected function readTag()
    {
        if ($this->char!=='<')
        {
            $this->root->_[SHD::HDOM_INFO_END] = $this->cursor;
            return false;
        }
        $beginTagPos = $this->pos;
        $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next

        // end tag
        if ($this->char==='/')
        {
            $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
            // This represents the change in the simple_html_dom trunk from revision 180 to 181.
            // $this->skip($this->tokenBlank_t);
            $this->skip($this->tokenBlank);
            $tag = $this->copyUntilChar('>');

            // skip attributes in end tag
            if (($pos = strpos($tag, ' '))!==false)
                $tag = substr($tag, 0, $pos);

            $parentLower = strtolower($this->parent->tag);
            $tagLower = strtolower($tag);

            if ($parentLower!==$tagLower)
            {
                if (isset($this->optionalClosingTags[$parentLower]) && isset($this->blockTags[$tagLower]))
                {
                    $this->parent->_[SHD::HDOM_INFO_END] = 0;
                    $org_parent = $this->parent;

                    while (($this->parent->parent) && strtolower($this->parent->tag)!==$tagLower)
                        $this->parent = $this->parent->parent;

                    if (strtolower($this->parent->tag)!==$tagLower) {
                        $this->parent = $org_parent; // restore origonal parent
                        if ($this->parent->parent) $this->parent = $this->parent->parent;
                        $this->parent->_[SHD::HDOM_INFO_END] = $this->cursor;
                        return $this->asTextNode($tag);
                    }
                }
                else if (($this->parent->parent) && isset($this->blockTags[$tagLower]))
                {
                    $this->parent->_[SHD::HDOM_INFO_END] = 0;
                    $org_parent = $this->parent;

                    while (($this->parent->parent) && strtolower($this->parent->tag)!==$tagLower)
                        $this->parent = $this->parent->parent;

                    if (strtolower($this->parent->tag)!==$tagLower)
                    {
                        $this->parent = $org_parent; // restore origonal parent
                        $this->parent->_[SHD::HDOM_INFO_END] = $this->cursor;
                        return $this->asTextNode($tag);
                    }
                }
                else if (($this->parent->parent) && strtolower($this->parent->parent->tag)===$tagLower)
                {
                    $this->parent->_[SHD::HDOM_INFO_END] = 0;
                    $this->parent = $this->parent->parent;
                }
                else
                    return $this->asTextNode($tag);
            }

            $this->parent->_[SHD::HDOM_INFO_END] = $this->cursor;
            if ($this->parent->parent) $this->parent = $this->parent->parent;

            $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
            return true;
        }

        $node = new SimpleHtmlDomNode($this);
        $node->_[SHD::HDOM_INFO_BEGIN] = $this->cursor;
        ++$this->cursor;
        $tag = $this->copyUntil($this->tokenSlash);
        $node->tagStart = $beginTagPos;

        // doctype, cdata & comments...
        if (isset($tag[0]) && $tag[0]==='!') {
            $node->_[SHD::HDOM_INFO_TEXT] = '<' . $tag . $this->copyUntilChar('>');

            if (isset($tag[2]) && $tag[1]==='-' && $tag[2]==='-') {
                $node->nodetype = SHD::HDOM_TYPE_COMMENT;
                $node->tag = 'comment';
            } else {
                $node->nodetype = SHD::HDOM_TYPE_UNKNOWN;
                $node->tag = 'unknown';
            }
            if ($this->char==='>') $node->_[SHD::HDOM_INFO_TEXT].='>';
            $this->linkNodes($node, true);
            $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
            return true;
        }

        // text
        if ($pos=strpos($tag, '<')!==false) {
            $tag = '<' . substr($tag, 0, -1);
            $node->_[SHD::HDOM_INFO_TEXT] = $tag;
            $this->linkNodes($node, false);
            $this->char = $this->doc[--$this->pos]; // prev
            return true;
        }

        if (!preg_match("/^[\w-:]+$/", $tag)) {
            $node->_[SHD::HDOM_INFO_TEXT] = '<' . $tag . $this->copyUntil('<>');
            if ($this->char==='<') {
                $this->linkNodes($node, false);
                return true;
            }

            if ($this->char==='>') $node->_[SHD::HDOM_INFO_TEXT].='>';
            $this->linkNodes($node, false);
            $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
            return true;
        }

        // begin tag
        $node->nodetype = SHD::HDOM_TYPE_ELEMENT;
        $tagLower = strtolower($tag);
        $node->tag = ($this->lowercase) ? $tagLower : $tag;

        // handle optional closing tags
        if (isset($this->optionalClosingTags[$tagLower]) )
        {
            while (isset($this->optionalClosingTags[$tagLower][strtolower($this->parent->tag)]))
            {
                $this->parent->_[SHD::HDOM_INFO_END] = 0;
                $this->parent = $this->parent->parent;
            }
            $node->parent = $this->parent;
        }

        $guard = 0; // prevent infinity loop
        $space = array($this->copySkip($this->tokenBlank), '', '');

        // attributes
        do
        {
            if ($this->char!==null && $space[0]==='')
            {
                break;
            }
            $name = $this->copyUntil($this->tokenEqual);
            if ($guard===$this->pos)
            {
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                continue;
            }
            $guard = $this->pos;

            // handle endless '<'
            if ($this->pos>=$this->size-1 && $this->char!=='>') {
                $node->nodetype = SHD::HDOM_TYPE_TEXT;
                $node->_[SHD::HDOM_INFO_END] = 0;
                $node->_[SHD::HDOM_INFO_TEXT] = '<'.$tag . $space[0] . $name;
                $node->tag = 'text';
                $this->linkNodes($node, false);
                return true;
            }

            // handle mismatch '<'
            if ($this->doc[$this->pos-1]=='<') {
                $node->nodetype = SHD::HDOM_TYPE_TEXT;
                $node->tag = 'text';
                $node->attr = array();
                $node->_[SHD::HDOM_INFO_END] = 0;
                $node->_[SHD::HDOM_INFO_TEXT] = substr($this->doc, $beginTagPos, $this->pos-$beginTagPos-1);
                $this->pos -= 2;
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                $this->linkNodes($node, false);
                return true;
            }

            if ($name!=='/' && $name!=='') {
                $space[1] = $this->copySkip($this->tokenBlank);
                $name = $this->restoreNoise($name);
                if ($this->lowercase) $name = strtolower($name);
                if ($this->char==='=') {
                    $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                    $this->parseAttr($node, $name, $space);
                }
                else {
                    //no value attr: nowrap, checked selected...
                    $node->_[SHD::HDOM_INFO_QUOTE][] = SHD::HDOM_QUOTE_NO;
                    $node->attr[$name] = true;
                    if ($this->char!='>') $this->char = $this->doc[--$this->pos]; // prev
                }
                $node->_[SHD::HDOM_INFO_SPACE][] = $space;
                $space = array($this->copySkip($this->tokenBlank), '', '');
            }
            else
                break;
        } while ($this->char!=='>' && $this->char!=='/');

        $this->linkNodes($node, true);
        $node->_[SHD::HDOM_INFO_ENDSPACE] = $space[0];

        // check self closing
        if ($this->copyUntilCharEscape('>')==='/')
        {
            $node->_[SHD::HDOM_INFO_ENDSPACE] .= '/';
            $node->_[SHD::HDOM_INFO_END] = 0;
        }
        else
        {
            // reset parent
            if (!isset($this->selfClosingTags[strtolower($node->tag)])) $this->parent = $node;
        }
        $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next

        // If it's a BR tag, we need to set it's text to the default text.
        // This way when we see it in plaintext, we can generate formatting that the user wants.
        // since a br tag never has sub nodes, this works well.
        if ($node->tag == "br")
        {
            $node->_[SHD::HDOM_INFO_INNER] = $this->defaultBrText;
        }

        return true;
    }

    // parse attributes
    protected function parseAttr($node, $name, &$space)
    {
        // Per sourceforge: http://sourceforge.net/tracker/?func=detail&aid=3061408&group_id=218559&atid=1044037
        // If the attribute is already defined inside a tag, only pay atetntion to the first one as opposed to the last one.
        if (isset($node->attr[$name]))
        {
            return;
        }

        $space[2] = $this->copySkip($this->tokenBlank);
        switch ($this->char) {
            case '"':
                $node->_[SHD::HDOM_INFO_QUOTE][] = SHD::HDOM_QUOTE_DOUBLE;
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                $node->attr[$name] = $this->restoreNoise($this->copyUntilCharEscape('"'));
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                break;
            case '\'':
                $node->_[SHD::HDOM_INFO_QUOTE][] = SHD::HDOM_QUOTE_SINGLE;
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                $node->attr[$name] = $this->restoreNoise($this->copyUntilCharEscape('\''));
                $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
                break;
            default:
                $node->_[SHD::HDOM_INFO_QUOTE][] = SHD::HDOM_QUOTE_NO;
                $node->attr[$name] = $this->restoreNoise($this->copyUntil($this->token_attr));
        }
        // PaperG: Attributes should not have \r or \n in them, that counts as html whitespace.
        $node->attr[$name] = str_replace("\r", "", $node->attr[$name]);
        $node->attr[$name] = str_replace("\n", "", $node->attr[$name]);
        // PaperG: If this is a "class" selector, lets get rid of the preceeding and trailing space since some people leave it in the multi class case.
        if ($name == "class") {
            $node->attr[$name] = trim($node->attr[$name]);
        }
    }

    // link node's parent
    protected function linkNodes(&$node, $is_child)
    {
        $node->parent = $this->parent;
        $this->parent->nodes[] = $node;
        if ($is_child)
        {
            $this->parent->children[] = $node;
        }
    }

    // as a text node
    protected function asTextNode($tag)
    {
        $node = new SimpleHtmlDomNode($this);
        ++$this->cursor;
        $node->_[SHD::HDOM_INFO_TEXT] = '</' . $tag . '>';
        $this->linkNodes($node, false);
        $this->char = (++$this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
        return true;
    }

    protected function skip($chars)
    {
        $this->pos += strspn($this->doc, $chars, $this->pos);
        $this->char = ($this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
    }

    protected function copySkip($chars)
    {
        $pos = $this->pos;
        $len = strspn($this->doc, $chars, $pos);
        $this->pos += $len;
        $this->char = ($this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
        if ($len===0) return '';
        return substr($this->doc, $pos, $len);
    }

    protected function copyUntil($chars)
    {
        $pos = $this->pos;
        $len = strcspn($this->doc, $chars, $pos);
        $this->pos += $len;
        $this->char = ($this->pos<$this->size) ? $this->doc[$this->pos] : null; // next
        return substr($this->doc, $pos, $len);
    }

    protected function copyUntilChar($char)
    {
        if ($this->char===null) return '';

        if (($pos = strpos($this->doc, $char, $this->pos))===false) {
            $ret = substr($this->doc, $this->pos, $this->size-$this->pos);
            $this->char = null;
            $this->pos = $this->size;
            return $ret;
        }

        if ($pos===$this->pos) return '';
        $posOld = $this->pos;
        $this->char = $this->doc[$pos];
        $this->pos = $pos;
        return substr($this->doc, $posOld, $pos-$posOld);
    }

    protected function copyUntilCharEscape($char)
    {
        if ($this->char===null) return '';

        $start = $this->pos;
        while (1)
        {
            if (($pos = strpos($this->doc, $char, $start))===false)
            {
                $ret = substr($this->doc, $this->pos, $this->size-$this->pos);
                $this->char = null;
                $this->pos = $this->size;
                return $ret;
            }

            if ($pos===$this->pos) return '';

            if ($this->doc[$pos-1]==='\\') {
                $start = $pos+1;
                continue;
            }

            $posOld = $this->pos;
            $this->char = $this->doc[$pos];
            $this->pos = $pos;
            return substr($this->doc, $posOld, $pos-$posOld);
        }
    }

    // remove noise from html content
    // save the noise in the $this->noise array.
    protected function removeNoise($pattern, $removeTag=false)
    {
        global $debugObject;
        if (is_object($debugObject)) { $debugObject->debugLogEntry(1); }

        $count = preg_match_all($pattern, $this->doc, $matches, PREG_SET_ORDER|PREG_OFFSET_CAPTURE);

        for ($i=$count-1; $i>-1; --$i)
        {
            $key = '___noise___'.sprintf('% 5d', count($this->noise)+1000);
            if (is_object($debugObject)) { $debugObject->debugLog(2, 'key is: ' . $key); }
            $idx = ($removeTag) ? 0 : 1;
            $this->noise[$key] = $matches[$i][$idx][0];
            $this->doc = substr_replace($this->doc, $key, $matches[$i][$idx][1], strlen($matches[$i][$idx][0]));
        }

        // reset the length of content
        $this->size = strlen($this->doc);
        if ($this->size>0)
        {
            $this->char = $this->doc[0];
        }
    }

    // restore noise to html content
    function restoreNoise($text)
    {
        global $debugObject;
        if (is_object($debugObject)) { $debugObject->debugLogEntry(1); }

        while (($pos=strpos($text, '___noise___'))!==false)
        {
            // Sometimes there is a broken piece of markup, and we don't GET the pos+11 etc... token which indicates a problem outside of us...
            if (strlen($text) > $pos+15)
            {
                $key = '___noise___'.$text[$pos+11].$text[$pos+12].$text[$pos+13].$text[$pos+14].$text[$pos+15];
                if (is_object($debugObject)) { $debugObject->debugLog(2, 'located key of: ' . $key); }

                if (isset($this->noise[$key]))
                {
                    $text = substr($text, 0, $pos).$this->noise[$key].substr($text, $pos+16);
                }
                else
                {
                    // do this to prevent an infinite loop.
                    $text = substr($text, 0, $pos).'UNDEFINED NOISE FOR KEY: '.$key . substr($text, $pos+16);
                }
            }
            else
            {
                // There is no valid key being given back to us... We must get rid of the ___noise___ or we will have a problem.
                $text = substr($text, 0, $pos).'NO NUMERIC NOISE KEY' . substr($text, $pos+11);
            }
        }
        return $text;
    }

    // Sometimes we NEED one of the noise elements.
    function searchNoise($text)
    {
        global $debugObject;
        if (is_object($debugObject)) { $debugObject->debugLogEntry(1); }

        foreach($this->noise as $noiseElement)
        {
            if (strpos($noiseElement, $text)!==false)
            {
                return $noiseElement;
            }
        }
    }
    function __toString()
    {
        return $this->root->innertext();
    }

    function __get($name)
    {
        switch ($name)
        {
            case 'outertext':
                return $this->root->innertext();
            case 'innertext':
                return $this->root->innertext();
            case 'plaintext':
                return $this->root->text();
            case 'charset':
                return $this->_charset;
            case 'target_charset':
                return $this->_targetCharset;
        }
    }

    // camel naming conventions
    function childNodes($idx=-1) {return $this->root->childNodes($idx);}
    function firstChild() {return $this->root->firstChild();}
    function lastChild() {return $this->root->lastChild();}
    function createElement($name, $value=null) {return @str_get_html("<$name>$value</$name>")->firstChild();}
    function createTextNode($value) {return @end(str_get_html($value)->nodes);}
    function getElementById($id) {return $this->find("#$id", 0);}
    function getElementsById($id, $idx=null) {return $this->find("#$id", $idx);}
    function getElementByTagName($name) {return $this->find($name, 0);}
    function getElementsByTagName($name, $idx=-1) {return $this->find($name, $idx);}
}
