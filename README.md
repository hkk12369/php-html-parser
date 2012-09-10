PHP HTML Parser
===============

A simple and efficient DOMDocument based PHP HTML and XML Parser. It accepts both css selector and xpath queries to search the document and handles malformed HTML as well.

Example:
```php
$html = HtmlParser::from_string('<div id="outer"><span class="red">Some Text</span></div>');
$text = $html->find('#outer .red', 0)->text;
echo $text;   // outputs "Some Text"
```