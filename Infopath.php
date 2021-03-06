<?php
/**
 * Microsoft Infopath file format reader.
 * 
 * PHP Version 5
 * 
 * Notes:
 *  - Does not yet support structured fields.  Only reads fields in the base group.
 *  - There is an inbuilt restriction on the characters that can be used for field
 *    and group names.  Only alphanumeric and underscores/hyphens/full stops allowed.
 *    (Must only begin with alphabetic or underscore)
 * 
 * Infopath Usage:
 *  - Infopath does not have the ability to associate multiple checkboxes to one
 *    field.  There is a similar problem for multi-selects and Infopath does not
 *    allow these controls when in web compatible mode.  To overcome this problem
 *    and treat checkboxes as part of the one field when read by this class, they
 *    need to be put together into groups under "Data Source" section.  However,
 *    there is also another problem where fields cannot have the same name even if
 *    they exist in separate groups, so the fieldnames need to be prefixed with the
 *    group's name in order to avoid conflicts.
 *  - Use the "align" function when adding text fields with labels (not necessary on
 *    other boxes for checkboxes and radio buttons)
 * 
 * Resources:
 *   Infopath Devel Community
 *   http://www.infopathdev.com
 * 
 *   Infopath Developer Portal 
 *   http://msdn2.microsoft.com/en-us/office/aa905434.aspx
 * 
 *   MSDN - Infopath 2007
 *   Contains developer reference, xsf schema reference, technical articles, etc
 *   http://msdn2.microsoft.com/en-us/library/bb979620.aspx
 *
 * Possible Future Features:
 *  - Online and offline forms saving to xml format using javascript for offline
 *  - Repeating sections using javascript
 *  - Add form validation (server side or javascript?)
 *  - Handle rich text area?
 *  - Open from source files (File -> Save as source files...)
 * 
 * Package Dependencies:
 *   - File_Cabinet
 *   - XSL extension (optional)
 * 
 * @category File Formats
 * @package File_Infopath
 * @author David Sanders <shangxiao@php.net>
 * @license http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @link http://pear.php.net/package/File_Infopath
 * @version @package_version@
 *
 */

require_once 'PEAR/Exception.php';
require_once 'File/Cabinet.php';

class File_Infopath_Exception extends PEAR_Exception {};

/**
 * Microsoft Infopath file format reader.
 * 
 * @category File Formats
 * @package File_Infopath
 * @author David Sanders <shangxiao@php.net>
 * @license http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @link http://pear.php.net/package/File_Infopath
 * @version @package_version@
 *
 */
class File_Infopath
{
    /**
     * File MIME type
     */
    const MIME_TYPE = 'application/ms-infopath.xml';

    /**
     * W3 XSD namespace
     */
    const XSD_NAMESPACE = 'http://www.w3.org/2001/XMLSchema';

    /**
     * W3 XSL namespace
     */
    const XSL_NAMESPACE = 'http://www.w3.org/1999/XSL/Transform';

    /**
     * Infopath's XSF namespace
     */
    const XSF_NAMESPACE = 'http://schemas.microsoft.com/office/infopath/2003/solutionDefinition';

    /**
     * Infopath's XD namespace
     */
    const XD_NAMESPACE  = 'http://schemas.microsoft.com/office/infopath/2003';

    /**
     * Default trim list with 0xC2 character - a capital A with a circumflex
     * and a 0xA0 character - a non-breaking space seem to appear for some 
     * unknown reason.
     */
    const TRIM_LIST = " \t\n\r\0\x0B\xC2\xA0";

    static public $treatGroupedCboxesAsSelects = true;

    /**
     * Cabinet extractor
     * 
     * @access private
     * @var File_Cabinet
     */
    private $_cab;

    /**
     * List of file's views
     * 
     * @access private
     * @var array
     */
    private $_views = array();

    /**
     * Submit information retrieved from manifest.xsf
     *
     * @access private
     * @var associative array
     */
    private $_submit = array();

    /**
     * The submit button's text
     * 
     * @access private
     * @var string
     */
    private $_submit_text;

    /**
     * Name of the root group, usually set to "myFields" by Infopath
     * 
     * @access private
     * @var string
     */
    private $_root_element;

    /**
     * Constructor
     *
     * @param string $filename Infopath file's name
     */
    public function __construct($filename)
    {
        // Instantiate the Cabinet reader
        $this->_cab = new File_Cabinet($filename);

        // Read manifest.xsf to obtain information about the form
        $this->_readManifest();
    }

    /**
     * Read some general information about the form: Root element, views and submit
     * information.
     * 
     * @access private
     * @return void
     */
    private function _readManifest()
    {
        $manifest = new DOMDocument;
        $manifest->loadXML($this->_cab->extract('manifest.xsf'));

        $xpath = new DOMXPath($manifest);

        // Root element
        $list = $xpath->query('/xsf:xDocumentClass/xsf:package/xsf:files/xsf:file[@name="myschema.xsd"]/xsf:fileProperties/xsf:property[@name="rootElement"]/@value');
        if ($list->length === 0) {
            throw new File_Infopath_Exception('Error retrieving root element from document');
        }
        $this->_root_element = $list->item(0)->value;

        // Obtain the list of views
        $list = $xpath->query('/xsf:xDocumentClass/xsf:views/xsf:view');
        if ($list->length === 0) {
            throw new File_Infopath_Exception('Error retrieving views from document');
        }
        foreach ($list as $view) {
            $mainpane = $view->getElementsByTagNameNS(self::XSF_NAMESPACE, 'mainpane')->item(0);
            $this->_views[$view->getAttribute('name')] = $mainpane->getAttribute('transform');
        }

        // Obtain any submit information, if available
        $submit = $xpath->query('/xsf:xDocumentClass/submit')->item(0);
        if (!is_null($submit)) {
            $this->_submit_text = $submit->getAttribute('caption');
            $http_handler = $element->getElementsByTagNameNS(self::XSF_NAMESPACE, 'useHttpHandler')->item(0);
            $this->_submit['action'] = $http_handler->getAttribute('href');
            $this->_submit['method'] = $http_handler->getAttribute('method');
        }
    }

    /**
     * Return the schema in a php friendly format.  The schema will be given
     * as an associative array in the following format:
     * 
     * 'type' => The type of the field,
     * 'default' => The default value as was given from Infopath,
     * 'required' => True/false depending on whether this was selected in Infopath,
     * 'size' => The size of the field,
     * 'options' => If this field has specific options, they will be listed here
     * 'option_type' => One of either: select/multiselect/radio/checkbox
     * 
     * @return array An associative array describing the schema.
     * @access public
     */
    public function getSchema()
    {
        // Grab the schema definition from myschema.xsd
        $myschema = new DOMDocument;
        $myschema->loadXML($this->_cab->extract('myschema.xsd'));
        $xpath = new DOMXPath($myschema);
        $schema = array();

        // is there an easier way to specify existence of the attribute "type"?
        foreach ($xpath->query('/xsd:schema/xsd:element[@name!="' . $this->_root_element . '"]/@type/..') as $element) {

            $attributes = array(
                'default'  => null,
                'required' => false,
                'size'     => null, 
                );

            // In <schema>.xsd the field types will either be from "xsd" or "my" namespace
            list(, $type) = explode(':', $element->getAttribute('type'));

            if ($type === 'requiredString') {
                $attributes['type'] = 'string';
                $attributes['required'] = true;
            } else {
                $attributes['type'] = $type;
            }

            $schema[$element->getAttribute('name')] = $attributes;
        }


        // Grab the defaults from template.xml
        // (Also available from sampledata.xml)
        $template = new DOMDocument;
        $template->loadXML($this->_cab->extract('template.xml'));
        $my_fields = $template->getElementsByTagName($this->_root_element)->item(0);
        $my_namespace = $my_fields->getAttribute('xmlns:my');
        foreach ($my_fields->getElementsByTagNameNS($my_namespace, '*') as $element) {
            if ($element->textContent !== '') {
                $schema[$element->localName]['default'] = $element->textContent;
            }
        }


        // grab the valid options from the default view

        // select options
        $view1 = new DOMDocument;
        $view1->loadXML($this->_cab->extract('view1.xsl'));
        foreach ($view1->getElementsByTagName('select') as $element) {
            $binding = $element->getAttributeNS(self::XD_NAMESPACE, 'binding');
            if ($binding !== '') {
                list(, $field_name) = explode(':', $binding);
                if ($element->getAttributeNS(self::XD_NAMESPACE, 'xctname') === 'ListBox') {
                    $schema[$field_name]['option_type'] = 'multiselect';
                } else {
                    $schema[$field_name]['option_type'] = 'select';
                }
                foreach ($element->getElementsByTagName('option') as $option) {
                    // trim indentation and remove xsl tags
                    // (alternatively could do the transformation?)
                    $if = $option->getElementsByTagNameNS(self::XSL_NAMESPACE, 'if')->item(0);
                    $option->removeChild($if);
                    $schema[$field_name]['options'][$option->getAttribute('value')] = trim($option->textContent, self::TRIM_LIST);
                }
            }
        }

        // radio button options
        foreach ($view1->getElementsByTagName('input') as $element) {
            if ($element->getAttribute('type') === 'radio') {
                list(, $field_name) = explode(':', $element->getAttributeNS(self::XD_NAMESPACE, 'binding'));
                $cloned_div = $element->parentNode->cloneNode(true);
                $cloned_input = $cloned_div->getElementsByTagName('input')->item(0);
                $cloned_div->removeChild($cloned_input);
                $schema[$field_name]['options'][$element->getAttributeNS(self::XD_NAMESPACE, 'onValue')] = trim($cloned_div->textContent, self::TRIM_LIST);
                $schema[$field_name]['option_type'] = 'radio';
            }
        }

        // checkbox options
        // select any element that has a complextype child, that isn't the root element
        // note: is it possible to extract multiple elements out of a path?
        foreach ($xpath->query('/xsd:schema/xsd:element[@name!="' . $this->_root_element . '"]/xsd:complexType/..') as $group) {
            $group_name = $group->getAttribute('name');
            $elements = array();
            $element_other = null;
            foreach ($group->getElementsByTagNameNS(self::XSD_NAMESPACE, 'element') as $element) {
                list(, $element_ref) = explode(':', $element->getAttribute('ref'));
                $all_there = true;
                // if it has the prefix and is a boolean
                $found_element_binding = preg_match('/^' . $group_name . '_/', $element_ref);
                if ($found_element_binding && $schema[$element_ref]['type'] === 'boolean') {
                    $elements[] = $element_ref;
                } else if ($found_element_binding && preg_match('/_other$/', $element_ref) && $schema[$element_ref]['type'] === 'string') {
                    $element_other = $element_ref;
                } else {
                    $all_there = false;
                    break;
                }
            }
            if ($all_there) {
                $view1_xpath = new DOMXPath($view1);
                $schema[$group_name] = array(
                    'type'        => 'string',
                    'required'    => false, // fixme!
                    'default'     => null, // fixme!
                    'options'     => array(),
                    'size'        => null,
                    'option_type' => 'checkbox',
                    'other'       => null,
                );
                foreach ($elements as $element) {
                    $option_name = preg_replace('/^' . $group_name . '_/', '', $element);
                    unset($schema[$element]);

                    // attempt to retrieve option label
                    $option_div = $view1_xpath->query('//input[@xd:binding="my:' . $group_name . '/my:' . $element . '"]/ancestor::div')->item(0)->cloneNode(true);
                    $input = $option_div->getElementsByTagName('input')->item(0); // spans seem to start and end in any old arbitrary spot
                    $input->parentNode->removeChild($input);

                    $schema[$group_name]['options'][$option_name] = trim($option_div->textContent, self::TRIM_LIST);
                }
                if (!is_null($element_other)) {
                    unset($schema[$element_other]);
                    $option_div = $view1_xpath->query('//span[@xd:binding="my:' . $group_name . '/my:' . $element_other . '"]/ancestor::div')->item(0);
                    $schema[$group_name]['other'] = true;
                    $schema[$group_name]['other_label'] = trim($option_div->textContent, self::TRIM_LIST);
                }
            }
        }

        return $schema;
    }

    /**
     * List the names of the views available
     * 
     * @access public
     * @return array An array of view names
     */
    public function listViews()
    {
        return array_keys($this->_views);
    }

    /**
     * Get a view given a view's name.  The views are stored as HTML. 
     * 
     * @param string $name The view name
     * @param array $form_attrs If null, the view will be left as is.  If true, the 
     *                          submit attributes from Infopath will be used.  Otherwise
     *                          pass an associative array of <form> attributes.
     *
     * @return HTML The view
     * @access public
     */
    public function getView($name, $form_attrs = null)
    {
        if (!extension_loaded('xsl')) {
            throw new File_Infopath_Exception(__CLASS__ . '::' . __METHOD__ . '() requires the xsl extension');
        }

        $xsl = new DOMDocument;
        $xsl->loadXML($this->_cab->extract($this->_views[$name]));

        if (!is_null($form_attrs)) {
            // change any span of xctname of PlainText into a text input
            // $spans = $xsl->getElementsByTagName('span'); bug??
            foreach ($xsl->getElementsByTagName('*') as $element) {

                $field_name = $element->getAttributeNS(self::XD_NAMESPACE, 'binding');

                if ($field_name !== '' && $element->getAttributeNS(self::XD_NAMESPACE, 'xctname') === 'PlainText') {

                    $input = $xsl->createElement('input');

                    $att_type = $xsl->createElementNS(self::XSL_NAMESPACE,'attribute');
                    $att_type->setAttribute('name','type');
                    $att_type->nodeValue = 'text';
                    $input->appendChild($att_type);

                    $att_name = $xsl->createElementNS(self::XSL_NAMESPACE, 'attribute');
                    $att_name->setAttribute('name', 'name');
                    $att_name->nodeValue = $field_name;
                    $input->appendChild($att_name);

                    $att_value = $xsl->createElementNS(self::XSL_NAMESPACE,'attribute');
                    $att_value->setAttribute('name','value');
                    $att_value->appendChild($element->getElementsByTagName('value-of')->item(0));
                    $input->appendChild($att_value);

                    $element->parentNode->replaceChild($input, $element);
                }
            }

            // wrap the entire contents of <body> in a <form> tag
            $body = $xsl->getElementsByTagName('body')->item(0);
            $form = $xsl->createElement('form');
            if (!is_array($form_attrs)) {
                $form_attrs = $this->_submit;
            }
            foreach ($form_attrs as $key => $val) {
                $form->setAttribute($key, $val);
            }
            foreach ($body->childNodes as $element) {
                // Something's up with these DOMNodeList thingies you can't remove the element or append it without cloning
                // otherwise it stuffs up the foreach?
                $form->appendChild($element->cloneNode(true));
            }
            $body->nodeValue = '';
            $body->appendChild($form);
        }

        $template = new DOMDocument;
        $template->loadXML($this->_cab->extract('template.xml'));

        $xslt = new XSLTProcessor;
        $xslt->importStylesheet($xsl);
        return $xslt->transformToXML($template);
    }

    /**
     * Extract a file from the Cabinet.  Useful to retrieve a view's images.
     * 
     * @access public
     * @param filename Name of file to extract
     * @return string File contents
     */
    public function extract($filename)
    {
        return $this->_cab->extract($filename);
    }

    /**
     * Generate a Savant template with DB_DataObject_FormBuilder hooks given the
     * html from a view.
     *
     * @param string $html The raw view html received from getView()
     * @param callback $field_name_converter A callback to remove underscores from field names.
     * @return string A Savant template with hooks for DB_DataObject_FormBuilder
     * @access public
     */
    static public function convertTemplate($html, $field_name_converter = array('File_Infopath', 'convertFieldNames'))
    {
        $template = new DOMDocument;
        $template->loadHTML($html);
        $body = $template->getElementsByTagName('body')->item(0);
        $form = $template->createElement('form');
        foreach ($body->childNodes as $element) {
            $form->appendChild($element->cloneNode(true));
        }
        $body->nodeValue = '';
        $body->appendChild($form);

        foreach ($template->getElementsByTagName('*') as $element) {
            // no namespaces, in html mode
            $field_name = $element->getAttribute('xd:binding');
            $field_type = $element->getAttribute('xd:xctname');
            if ($field_name !== '' &&
               ($field_type === 'PlainText'       ||
                $field_type === 'combobox'        ||
                $field_type === 'dropdown'        ||
                $field_type === 'OptionButton'    ||
                $field_type === 'DTPicker_DTText' ||
                $field_type === 'ListBox'         ||
                $field_type === 'CheckBox')) {

                list(, $field_name) = explode(':', $field_name);

                // A callback to allow the user to deal with how to replace hyphens
                $field_name = call_user_func($field_name_converter, $field_name);

                $instruction = $template->createProcessingInstruction('php', "echo \$this->form['{$field_name}']['html']?");
                if ($field_type === 'OptionButton') {
                    $font_container = $element->parentNode->parentNode;
                    $font_container->parentNode->replaceChild($instruction, $font_container);
                } else if ($field_type === 'DTPicker_DTText') {
                    $div = $element->parentNode;
                    $div->parentNode->replaceChild($instruction, $div);
                } else {
                    $element->parentNode->replaceChild($instruction, $element);
                }
            } else if ($element->getAttribute('type') === 'button' && $element->getAttribute('value') === 'Submit') {
                $instruction = $template->createProcessingInstruction('php', "echo \$this->form['__submit__']['html']?");
                $element->parentNode->replaceChild($instruction, $element);
            }
        }

        $template_html = $template->saveHTML();
        $template_html = str_replace('<form>', '<form <?php echo $this->form[\'attributes\']?>>', $template_html);
        return $template_html;
    }

    /**
     * Default callback for converting hyphens (which are illegal in php variable names)
     * 
     * @param string $field_name The infopath field name to convert
     * @return string The converted field name
     * @access public
     */
    static public function convertFieldNames($field_name)
    {
        return str_replace('-', '_', $field_name);
    }

    /**
     * Given a list of values save the file in the appropriate xml format.
     * 
     * @access public
     * @todo
     */
    public function saveForm($filename, $data)
    {
        throw new File_Infopath_Exception('Not yet implemented');
    }
}

?>
