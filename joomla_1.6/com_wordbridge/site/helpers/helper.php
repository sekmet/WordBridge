<?php
/**
 * @version     $Id$
 * @package  Wordbridge
 * @copyright   Copyright (C) 2011 Cognidox Ltd
 * @license  GNU AFFERO GENERAL PUBLIC LICENSE v3
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

class WordbridgeHelper {

    /**
     * getTotalBlogPosts
     * @return int the total number of blog posts for the blog
     */
    function getBlogInfo( $blogname = null, $useStored = false )
    {
        $info = array( 'count' => 0,
                       'description' => '',
                       'last_post_id' => 0,
                       'updated' => 0,
                       'last_post' => '',
                       'name' => '',
                       'id' => '' );

        if ( $blogname == null )
        {
            $app = &JFactory::getApplication();
            $params = &$app->getParams();
            $blogname = $params->get( 'wordbridge_blog_name' );
        }
        if ( empty( $blogname ) || ! function_exists( 'curl_init' ) )
        {
            return $info;
        }
        $info['name'] = $blogname;
        $stored_blog = WordbridgeHelper::getBlogByName( $blogname );
        if ( $stored_blog && $useStored )
        {
            return $stored_blog;
        }

        $url = sprintf( 'http://twitter-api.wordpress.com/users/show.xml?screen_name=%s.wordpress.com', urlencode( $blogname ) );
        $curl = curl_init();
        curl_setopt( $curl, CURLOPT_URL, $url );

        $xml = WordbridgeHelper::curl_redir_exec( $curl );
        curl_close( $curl );
        if ( empty( $xml ) )
        {
            return $info;
        }

        $doc = new DOMDocument();
        $doc->loadXML( $xml );
        $info['count'] = $doc->getElementsByTagName( 'statuses_count' )->item( 0 )->textContent;
        $info['description'] = $doc->getElementsByTagName( 'description' )->item( 0 )->textContent;
        $info['id'] = $doc->getElementsByTagName( 'id' )->item( 0 )->textContent;

        // Get the last post information, removing the blog ID that
        // comes out when using the twitter API
        $info['last_post_id'] = $doc->getElementsByTagName( 'status' )->item( 0 )->getElementsByTagName( 'id' )->item( 0 )->textContent;
        $info['last_post_id'] = (int)substr( $info['last_post_id'], strlen( $info['id'] ) );
        $info['last_post'] = $doc->getElementsByTagName( 'status' )->item( 0 )->getElementsByTagName( 'text' )->item( 0 )->textContent;

        // Update the stored blog basic details if need be
        if ( !empty( $info['description'] ) )
        {
            if ( $stored_blog )
            {
                if ( $stored_blog['description'] != $info['description'] ||
                     $stored_blog['name'] != $blogname ||
                     $stored_blog['last_post'] != $info['last_post'] )
                {
                    WordbridgeHelper::storeBlog( $info['id'], $blogname, $info['description'], $info['last_post'] );
                }
            }
            else
            {
                // Store the blog data locally
                WordbridgeHelper::storeBlog( $info['id'], $blogname, $info['description'], $info['last_post'] );
            }
        }
        return $info;
    }


    /**
     * getBlogByID
     * Look up the locally stored blog details
     * @return array containing id and description if found, or null if not
     */
    function getBlogByID( $id )
    {
        $db =& JFactory::getDBO();
        $query = sprintf( 'SELECT blog_id, blog_name, description, last_post, UNIX_TIMESTAMP(updated) FROM #__com_wordbridge_blogs WHERE blog_id = %d', (int)$id );
        $db->setQuery( $query );
        $blog = $db->loadRow();
        if ( $blog == null )
        {
            return null;
        }
        return array( 'id' => $blog[0], 
                      'name' => $blog[1],
                      'description' => $blog[2],
                      'last_post' => $blog[3],
                      'updated' => $blog[4] );
    }

    /**
     * getBlogByName
     * Look up the locally stored blog details by name
     * @return array containing id, name and description if found, or null if not
     */
    function getBlogByName( $name )
    {
        $db =& JFactory::getDBO();
        $query = sprintf( 'SELECT blog_id, blog_name, description, last_post, UNIX_TIMESTAMP(updated) FROM #__com_wordbridge_blogs WHERE blog_name = %s', $db->quote( $name, true ) );
        $db->setQuery( $query );
        $blog = $db->loadRow();
        if ( $blog == null )
        {
            return null;
        }
        return array( 'id' => $blog[0], 
                      'name' => $blog[1],
                      'description' => $blog[2],
                      'last_post' => $blog[3],
                      'updated' => $blog[4] );
    }

    /**
     * storeBlog
     * Store the ID, name and description of a blog
     */
    function storeBlog( $id, $name, $description, $last_post )
    {
        $db =& JFactory::getDBO();
        $query = sprintf( 'REPLACE INTO #__com_wordbridge_blogs VALUES(%d, %s, %s, %s, NOW())', (int)$id, $db->quote( $name, true ), $db->quote( $description, true ), $db->quote( $last_post, true ) );
        $db->setQuery( $query );
        $db->query();
    }

    function nameToSlug( $name )
    {
        $name = strtolower( trim ( $name ) );
        $name = preg_replace( '/[\.\s]/', '-', $name );
        $name = preg_replace( '/[^\-a-z0-9]/', '', $name );
        $name = preg_replace( '/--+/', '-', $name );
        $name = preg_replace( '/^-|_$/', '', $name );
        return $name;
    }

    function storeBlogEntries( $entries, $blog_id )
    {
        $db =& JFactory::getDBO();
        foreach ( $entries as $entry )
        {
            // Update the locally cached post
            $post_query = sprintf( 
                'REPLACE INTO #__com_wordbridge_posts VALUES (%d, %d, %s, %s, %s, %s)', 
                $entry['postid'],
                $blog_id,
                $db->quote( $entry['title'], true ),
                $db->quote( $entry['content'], true ),
                $db->quote( strftime( '%F %T %Z', $entry['date'] ), true),
                $db->quote( $entry['slug'], true ) );
            $db->setQuery( $post_query );
            $db->query();

            // Update the post category settings
            $db->setQuery( sprintf( 'DELETE FROM #__com_wordbridge_post_categories WHERE post_id = %d AND blog_id = %d', $entry['postid'], $blog_id ) );
            $db->query();
            if ( count( $entry['categories'] ) )
            {
                foreach ( $entry['categories'] as $category )
                {
                    $db->setQuery( 
                        sprintf( 'INSERT INTO #__com_wordbridge_post_categories VALUES (%d, %d, %s)', $entry['postid'], $blog_id, $db->quote( $category, true ) ) );
                    $db->query();
                }
            }
        }
    }

    function getEntriesFromUrl( $url )
    {
        // Use curl to get the data
        $curl = curl_init();
        curl_setopt( $curl, CURLOPT_URL, $url );

        $xml = WordbridgeHelper::curl_redir_exec( $curl );
        curl_close( $curl );
        if ( empty( $xml ) )
        {
            return array();
        }

        $results = array();
        $doc = new DOMDocument();
        $doc->loadXML( $xml );
        $this->_title = $doc->getElementsByTagName( 'description' )->item( 0 )->textContent;
        $entries = $doc->getElementsByTagName( 'item' );
        foreach ( $entries as $item )
        {
            $title = $item->getElementsByTagName( 'title' )->item( 0 )->textContent;
            // Some blogs don't have a title - so try the description with
            // tags stripped out
            if ( empty( $title ) )
            {
                $title = $item->getElementsByTagName( 'description' )->item( 0 )->textContent;
                $title = strip_tags( $title );
                if ( strlen( $title ) > 60 )
                {
                    $title = substr( $title, 0, 59 ) . '...';
                }
            }

            $date = $item->getElementsByTagName( 'pubDate' )->item( 0 )->textContent;
            $content = $item->getElementsByTagNameNS( 'http://purl.org/rss/1.0/modules/content/', 'encoded' )->item( 0 )->textContent;

            // Work out the wordpress ID for this blog entry
            // Looks like older blogs use a different format where the
            // post id is not in the guid.
            $postid = null;
            $guid = $item->getElementsByTagName( 'guid' )->item( 0 )->textContent;
            if ( strpos( $guid, 'p=' ) !== false )
            {
                $guid_parts = explode( 'p=', $guid );
                if ( count( $guid_parts ) == 2 )
                {
                    $postid = $guid_parts[1];
                }
            }
            else
            {
                // Lookup the post id in the description, as that contains
                // a link to the stats
                $desc = $guid = $item->getElementsByTagName( 'description' )->item( 0 )->textContent;
                $matches = array();
                if ( preg_match( '/stats\.wordpress\.com\/b.gif[^"]+post=(\d+)/',  $desc, $matches ) )
                {
                    $postid = $matches[1];
                }
            }

            // Enumerate the wordpress categories for this entry
            $categories = array();
            foreach ( $item->getElementsByTagName( 'category' ) as $category )
            {
                $categories[] = $category->textContent;
            }

            // Get the human readable slug for this entry (may need for SEF)
            $slug = '';
            $feed_link = $item->getElementsByTagName( 'link' )->item( 0 )->textContent;
            if ( !empty( $feed_link ) )
            {
                $link_parts = explode( '/', $feed_link );
                $slug = $link_parts[ count( $link_parts ) - 2 ];
            }

            // Trim the links if need be
            if ( !$show_links )
            {
                $content = substr( $content, 0, strrpos( $content, '<br />' ) );
            }

            // Add the new entry to our blog entry list
            $results[] = array( 'title' => $title,
                                'postid' => $postid,
                                'categories' => $categories,
                                'slug' => $slug,
                                'date' => strtotime( $date ),
                                'content' => $content );
        }
        return $results;
    }

    /**
     * getWordbridgeMenuIDs
     * Return a list of menu IDs for Wordbridge items
     */
    function getWordbridgeMenuIDs()
    {
        $result = array();
        $db =& JFactory::getDBO();
        $query = "SELECT m.id FROM #__menu AS m LEFT JOIN #__extensions AS e ON m.component_id = e.extension_id WHERE e.name = 'com_wordbridge' and m.published = 1";
        $db->setQuery( $query );
        $menuIDs = $db->loadRowList();
        if ( $menuIDs != null && count( $menuIDs ) )
        {
            foreach ( $menuIDs as $mid )
            {
                $result[] = $mid[0];
            }
        }
        return $result;
    }

    /**
     * addTag
     * Store something as a tag
     */
    function addTag( $blog_id, $name )
    {
        $db =& JFactory::getDBO();
        $query = sprintf( 'REPLACE INTO #__com_wordbridge_blog_tags VALUES (%d, %s)', $blog_id, $db->quote( $name, true ) );
        $db->setQuery( $query );
        $db->query();
    }

    /**
     * addCategory
     * Store something as a category
     */
    function addCategory( $blog_id, $name )
    {
        $db =& JFactory::getDBO();
        $query = sprintf( 'REPLACE INTO #__com_wordbridge_blog_categories VALUES (%d, %s)', $blog_id, $db->quote( $name, true ) );
        $db->setQuery( $query );
        $db->query();
    }

    /**
     * isTag
     * Determine if something is a tag
     * @return boolean
     */
    function isTag( $blog_id, $name )
    {
        $db =& JFactory::getDBO();
        $query = sprintf( 'SELECT COUNT(*) FROM #__com_wordbridge_blog_tags WHERE blog_id = %d AND tag = %s', $blog_id, $db->quote( $name, true ) );
        $db->setQuery( $query );
        $tagCount = $db->loadResult();
        if ( $tagCount )
        {
            return true;
        }
        return false;
    }

    /**
     * curl_redir_exec
     * Work around safe_mode restrictions on CURLOPT_FOLLOWLOCATION
     * Taken from http://php.net/manual/en/function.curl-setopt.php#71313
     * eion at bigfoot.com
     */
    function curl_redir_exec( $ch, $curl_loops = 0 )
    {
        static $curl_max_loops = 20;
        if ( $curl_loops++ >= $curl_max_loops )
        {
            return FALSE;
        }
        curl_setopt( $ch, CURLOPT_HEADER, true );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $data = curl_exec( $ch );
        list( $header, $data ) = preg_split( '/(\r?\n){2}/', $data, 2 );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        if ( $http_code == 301 || $http_code == 302 )
        {
            $matches = array();
            preg_match( '/Location:(.*?)\n/', $header, $matches );
            $url = parse_url( trim( array_pop( $matches ) ) );
            if ( !$url )
            {
                //couldn't process the url to redirect to
                return $data;
            }
            $last_url = parse_url( curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL ) );
            if ( !$url['scheme'] )
                $url['scheme'] = $last_url['scheme'];
            if ( !$url['host'] )
                $url['host'] = $last_url['host'];
            if ( !$url['path'] )
                $url['path'] = $last_url['path'];
            $new_url = $url['scheme'] . '://' . $url['host'] . $url['path'] . ($url['query']?'?'.$url['query']:'');
            curl_setopt( $ch, CURLOPT_URL, $new_url );
            return WordbridgeHelper::curl_redir_exec( $ch, $curl_loops );
        }
        else
        {
            return $data;
        }
    }
}
