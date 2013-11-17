<?php

/**
* Author: Hitesh Kumar, IIT Delhi.
* License: http://en.wikipedia.org/wiki/WTFPL
*/

class HtmlParser
{
	public static function from_string($str, $xml = false)
	{
		libxml_use_internal_errors(true);
		$html = new DOMDocument();

		if($xml)
		{
			$html->loadXML($str);
		}
		else
		{
			$html->preserveWhiteSpace = true;
			$html->loadHTML($str);
		}

		libxml_clear_errors();

		$dom_xpath = new DOMXPath($html);
		return new HtmlNode($html->documentElement, $dom_xpath);
	}

	public static function from_file($file, $xml = false)
	{
		$str = file_get_contents($file);
		return self::from_string($str, $xml);
	}

	// Converts a given CSS Selector expression to xpath expression.
	// This function is direct port of firebug's css to xpath convertor.
	public static function css_to_xpath($rule)
	{
		$reg_element = '/^([#.]?)([a-z0-9\\*_-]*)((\|)([a-z0-9\\*_-]*))?/i';
		$reg_attr1 = '/^\[([^\]]*)\]/i';
		$reg_attr2 = '/^\[\s*([^~=\s]+)\s*(~?=)\s*"([^"]+)"\s*\]/i';
		$reg_attr3 = '/^\[\s*([^~=\s]+)\s*(~?=)\s*\'([^\']+)\'\s*\]/i';
		$reg_attr4 = '/^\[\s*([^~=\s]+)\s*(~?=)\s*([^\]]+)\s*\]/i';
		$reg_pseudo = '/^:([a-z_-]+)(\([a-z_-]+\))?/i';
		$reg_combinator = '/^(\s*[>+\s])?/i';
		$reg_comma = '/^\s*,/i';

		$index = 1;
		$parts = array(".//", "*");
		$last_rule = null;

		while ($rule && $rule !== $last_rule)
		{
			$last_rule = $rule;

			// Trim leading whitespace
			$rule = trim($rule);
			if (!$rule)
				break;

			// Match the element identifier
			preg_match($reg_element, $rule, $m);
			if ($m)
			{
				if (!$m[1])
				{
					// XXXjoe Namespace ignored for now
					if ($m[5])
						$parts[$index] = $m[5];
					else
						$parts[$index] = $m[2];
				}
				else if ($m[1] == '#')
					$parts[] = "[@id='" . $m[2] . "']";
				else if ($m[1] == '.')
					$parts[] = "[contains(concat(' ',@class,' '), ' " . $m[2] . " ')]";

				$rule = substr($rule, strlen($m[0]));
			}

			// Match attribute selectors
			preg_match($reg_attr4, $rule, $m);
			if(!$m) preg_match($reg_attr3, $rule, $m);
			if(!$m) preg_match($reg_attr2, $rule, $m);
			if ($m)
			{
				if ($m[2] == "~=")
					$parts[] = "[contains(concat(' ', @" . $m[1] . ", ' '), ' " . $m[3] . " ')]";
				else
					$parts[] = "[@" . $m[1] . "='" . $m[3] . "']";

				$rule = substr($rule, strlen($m[0]));
			}
			else
			{
				preg_match($reg_attr1, $rule, $m);
				if ($m)
				{
					$parts[] = "[@" . $m[1] . "]";
					$rule = substr($rule, strlen($m[0]));
				}
			}

			// Skip over pseudo-classes and pseudo-elements, which are of no use to us
			preg_match($reg_pseudo, $rule, $m);
			while ($m)
			{
				// Handle Later
				// if($m[1] && $m[2])
				// {
				// 	$m[2] = substr($m[2], 1, -1);

				// 	switch($m[1])
				// 	{
				// 		case 'contains':
				// 			$parts[] = '[contains(translate(normalize-space(string(.)),"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),translate("'.$m[2].'","ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"))]';
				// 	}
				// }	

				$rule = substr($rule, strlen($m[0]));
				preg_match($reg_pseudo, $rule, $m);
			}

			// Match combinators
			preg_match($reg_combinator, $rule, $m);
			if ($m && strlen($m[0]))
			{
				if (strpos($m[0], ">") !== false)
					$parts[] = "/";
				else if (strpos($m[0], "+") !== false)
					$parts[] = "/following-sibling::";
				else
					$parts[] = "//";

				$index = count($parts);
				$parts[] = "*";
				$rule = substr($rule, strlen($m[0]));
			}

			preg_match($reg_comma, $rule, $m);
			if ($m)
			{
				$parts[] = " | ";
				$parts[] = ".//";
				$index = count($parts);
				$parts[] = "*";
				$rule = substr($rule, strlen($m[0]));
			}
		}

		$xpath = implode("", $parts);
		return $xpath;
	}
}

class HtmlNode
{
	private $dom_xpath;
	private $node;
	
	public function __construct($node, $dom_xpath = null)
	{
		$this->node = $node;
		if($dom_xpath) $this->dom_xpath = $dom_xpath;
	}

	// use $node->text for node's text.
	// use $node->html for node's inner HTML.
	// use $node->anything for node's attribute.
	public function __get($name)
	{
		if($name == 'text' || $name == 'plaintext')
			return $this->text();
		else if($name == 'html')
			return $this->html();
		else if($this->node->hasAttribute($name))
			return $this->node->getAttribute($name);
		else
			return null;
	}

	// finds nodes by css selector expression.
	// returns an array of nodes if $idx is not given, otherwise returns a single node at index $idx.
	// returns null if node not found or error in selector expression.
	// $idx can be negative (find from last).
	public function find($query, $idx = null)
	{
		$xpath = HtmlParser::css_to_xpath($query);
		return $this->xpath($xpath, $idx);
	}

	// finds nodes by xpath expression.
	public function xpath($xpath, $idx = null)
	{
		$result = $this->dom_xpath->query($xpath, $this->node);
		if($idx === null)
		{
			if(!$result) return array();
			return self::wrap_nodes($result, $this->dom_xpath);
		}
		else if($idx >= 0)
		{
			if(!$result) return null;
			return self::wrap_node($result->item($idx), $this->dom_xpath);
		}
		else
		{
			if(!$result) return null;
			return self::wrap_node($result->item($result->length + $idx), $this->dom_xpath);
		}
	}

	public function child($idx = null)
	{
		if(!$this->node->hasChildNodes())
			return array();

		$nodes = array();
		foreach($this->node->childNodes as $node)
		{
			if($node->nodeType === XML_ELEMENT_NODE)
				$nodes[] = $node;
		}

		if($idx === null)
		{
			if(!$nodes) return array();
			return self::wrap_nodes($nodes, $this->dom_xpath);
		}
		else if($idx >= 0)
		{
			if(!$nodes) return null;
			return self::wrap_node($nodes[$idx], $this->dom_xpath);
		}
		else
		{
			if(!$nodes) return null;
			return self::wrap_node($nodes[count($nodes) + $idx], $this->dom_xpath);
		}
	}

	public function has_child()
	{
		if($this->node->hasChildNodes())
		{
			foreach($this->node->childNodes as $node)
			{
				if($node->nodeType === XML_ELEMENT_NODE)
					return true;
			}
		}

		return false;
	}

	public function first_child()
	{
		$node = $this->node->firstChild;
		while($node && $node->nodeType !== XML_ELEMENT_NODE)
		{
			$node = $node->nextSibling;
		}

		return self::wrap_node($node, $this->dom_xpath);
	}

	public function last_child()
	{
		$node = $this->node->lastChild;
		while($node && $node->nodeType !== XML_ELEMENT_NODE)
		{
			$node = $node->previousSibling;
		}

		return self::wrap_node($node, $this->dom_xpath);
	}

	public function parent()
	{
		$node = $this->node->parentNode;
		while($node && $node->nodeType !== XML_ELEMENT_NODE)
		{
			$node = $node->parentNode;
		}

		return self::wrap_node($node, $this->dom_xpath);
	}

	public function next()
	{
		$node = $this->node->nextSibling;
		while($node && $node->nodeType !== XML_ELEMENT_NODE)
		{
			$node = $node->nextSibling;
		}

		return self::wrap_node($node, $this->dom_xpath);
	}

	public function prev()
	{
		$node = $this->node->previousSibling;
		while($node && $node->nodeType !== XML_ELEMENT_NODE)
		{
			$node = $node->previousSibling;
		}

		return self::wrap_node($node, $this->dom_xpath);
	}

	public function text()
	{
		return $this->node->nodeValue;
	}

	public function html() 
	{
		$tag = $this->node_name();
		return preg_replace('@(^<' . $tag . '[^>]*>)|(</' . $tag . '>$)@', '', $this->outer_html());
	}

	public function inner_html()
	{
		return $this->html();
	}

	public function outer_html()
	{
		$doc = new DOMDocument();
		$doc->appendChild($doc->importNode($this->node, TRUE));
		$html = trim($doc->saveHTML());
		return $html;
	}

	public function node_name()
	{
		return $this->node->nodeName;
	}

	// Wrap given DOMNodes in HtmlNode and return an array of them.
	private static function wrap_nodes($nodes, $dom_xpath = null)
	{
		$wrapped = array();
		foreach($nodes as $node)
		{
			$wrapped[] = new HtmlNode($node, $dom_xpath);
		}
		return $wrapped;
	}

	// Wrap a given DOMNode in HtmlNode.
	private static function wrap_node($node, $dom_xpath = null)
	{
		if($node == null) return null;
		return new HtmlNode($node, $dom_xpath);
	}
}
