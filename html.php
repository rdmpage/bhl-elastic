<?php

// The frame shared by the web pages: head with styles, navigation bar, main.

require_once (dirname(__FILE__) . '/config.inc.php');

//----------------------------------------------------------------------------------------
// Start of HTML document
function display_html_start($title = '', $entity = null)
{
	global $config;

	echo '<!DOCTYPE html>
<head>';

	echo '<meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';

	echo '<!-- base -->
    	<base href="' . $config['web_root'] . '" /><!--[if IE]></base><![endif]-->' . "\n";
    

    if ($title == '')
    {
    	$title = $config['site_name'];
    }
    echo '<title>' . htmlentities($title, ENT_HTML5). '</title>';
	
	echo '<style>';	
	require_once (dirname(__FILE__) . '/root.css.inc.php');
	require_once (dirname(__FILE__) . '/body.css.inc.php');
	require_once (dirname(__FILE__) . '/nav.css.inc.php');
	require_once (dirname(__FILE__) . '/results.css.inc.php');
	echo '</style>' . "\n";		
	
	if ($entity)
	{
		echo "<!-- JSON-LD -->\n";
		echo '<script type="application/ld+json">' . "\n";	
		echo json_encode($entity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
		echo '</script>' . "\n";	
	}

	echo '</head>';
	echo '<body>';
}

//----------------------------------------------------------------------------------------
// End of HTML document
function display_html_end()
{
	global $config;
	
	echo 
'<script>
<!-- any end of document script goes here -->
</script>';

	echo '</body>';
	echo '</html>';
}

//----------------------------------------------------------------------------------------
function display_main_start()
{
	echo '<main>';
}

//----------------------------------------------------------------------------------------
function display_main_end()
{
	echo '</main>';
}

//----------------------------------------------------------------------------------------
function display_navbar($q, $type = 'all')
{
	global $config;

	// Keep the kind of search when the query is changed
	$hidden = '';
	if ($type != 'all')
	{
		$hidden = '<input type="hidden" name="type" value="' . htmlspecialchars($type) . '">';
	}

	echo '<nav>
	<ul>
		<li><a href=".">Home</a></li>
		<li><a href="match.php">Match</a></li>
		<li class="search-item">
			<form method="get" action="index.php">
				<input class="search" id="search" name="q" type="text" placeholder="Search for name..." value="' . htmlspecialchars($q) . '">
				' . $hidden . '
				<input type="submit" value="Search">
			</form>
		</li>
	</ul>
	</nav>';

}

?>
