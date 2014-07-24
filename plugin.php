<?php

class DatawrapperPlugin_Oembed extends DatawrapperPlugin {
    const GET_PUBLISHED_URL_PATTERN = 'get_published_url_pattern';

    /*
     * Register the relevant hooks for this module.
     */
    public function init() {
        $plugin = $this;

        // Register the API endpoint
        DatawrapperHooks::register(
            DatawrapperHooks::PROVIDE_API,
            function() use ($plugin) {
                return array(
                    'url' => 'oembed',
                    'method' => 'GET',
                    'action' => function() use ($plugin) {
                        global $app;
                        return $plugin->oEmbedEndpoint($app);
                    }
                );
            }
        );

        // Register the oEmbed-link handler for the chart-head
        DatawrapperHooks::register(
            DatawrapperHooks::CHART_HTML_HEAD,
            function($chart) use ($plugin) {
                $plugin->oembedLink($chart);
            }
        );

        // Register the standard URLs for the URL patterns
        DatawrapperHooks::register(
            DatawrapperPlugin_Oembed::GET_PUBLISHED_URL_PATTERN,
            function() {
                return 'http[s]?:\/\/' . $GLOBALS['dw_config']['chart_domain'] . '\/(?<id>.+?)(?:[\/](?:index\.html)?)?';
            }
        );
    }

    /*
     * Handle requests to /api/plugin/oembed
     */
    protected function oEmbedEndpoint($app) {
        // Get the parameters from the query-parameters
        $url = urldecode($app->request()->get('url'));
        $format = $app->request()->get('format');

        // Get all the possible patterns for chart urls
        $results = DatawrapperHooks::execute(DatawrapperPlugin_Oembed::GET_PUBLISHED_URL_PATTERN);

        // Find the first pattern that matches the current url
        $found = false;
        foreach ($results AS $pattern) {
            if (preg_match('/^' . $pattern . '$/', $url, $matches)) {
                // We have a match.

                // Extract the id. If there is a named capture called 'id', then
                // use that. Otherwise, assume the id is in the first chapture
                // group
                $id = isset($matches['id']) ? $matches['id'] : $matches[1];

                // Check that the chart exists
                $chart = ChartQuery::create()->findPK($id);
                if (!$chart) break;

                // Check that the charts author is able to publish
                $user = $chart->getUser();
                if (!$user->isAbleToPublish()) break;

                // And check that the chart is public
                if (!$chart->isPublic()) break;

                // Get the oEmbed response
                self::chart_oembed($app, $chart);

                // and signal that we found a url
                $found = true;
                break;
            }
        }

        if (!$found) {
            // No hook returned something, so return a 404!
            $app->response()->status(404);
        }
    }

    /*
     * Print the oEmbed discovery-link in the chart head html.
     */
    protected function oembedLink($chart) {
        $content = get_chart_content($chart, $chart->getUser(), false, '../');

        $title = strip_tags(str_replace('<br />', ' - ', $chart->getTitle()));
        $url = urlencode($content['chartUrl']);

        echo '<link rel="alternate" type="application/json+oembed" href="' . $content['DW_DOMAIN'] . 'api/plugin/oembed?url=' . $url . '&format=json" title="' . $title . '" />' . "\n";
    }

    /*
     * Helper function: Can ensure that the dimentions of a chart are bounding
     * to fit inside a smaller bounding-box.
     * This function is inspired by `image_dimentions_scale` from Drupal 7.x
     */
    protected static function _dimention_bounding($dimentions, $bounding) {
        list($height, $width) = $dimentions;
        list($maxheight, $maxwidth) = $bounding;
        $aspect = $height / $width;

        // Ensure that both maxheight and maxwidth is set, and their aspect-ratio is
        // the same of that of height and width.
        if (($maxwidth && !$maxheight) || ($maxwidth && $maxheight && $aspect < $maxheight / $maxwidth)) {
            $maxheight = (int) round($maxwidth * $aspect);
        }
        else {
            $maxwidth = (int) round($maxheight / $aspect);
        }

        if ($maxheight < $height) {
            // Our bounding box is the smallest, so use that size
            return array($maxheight, $maxwidth);
        } else {
            // The chart is smaller than the bounding box, so use that size
            return array($height, $width);
        }
    }

    /*
     * Produce a oEmbed document for the chart with id $id, in the format $format.
     */
    protected static function chart_oembed($app, $chart) {
        if ($app->request()->get('format') != 'json') {
            // We currently don't support anything but JSON responses, so we return
            // a 501 Not Implemented.
            return $app->response()->status(501);
        }

        $metadata = $chart->getMetadata();
        $url = $chart->getPublicUrl();
        $dimentions = array(
            $metadata['publish']['embed-height'],
            $metadata['publish']['embed-width'],
        );

        if ($app->request()->get('maxheight') || $app->request()->get('maxwidth')) {
            // We have a bounding, so figure out how large we should return the
            // chart
            $bounding = array(
                (int) $app->request()->get('maxheight'),
                (int) $app->request()->get('maxwidth'),
            );
            $dimentions = self::_dimention_bounding($dimentions, $bounding);
        }

        // Generate the iframe to embed the chart
        list($height, $width) = $dimentions;
        $html = '<iframe src="' . $url . '" frameborder="0" ' .
                  'allowtransparency="true" ' .
                  'allowfullscreen="allowfullscreen" ' .
                  'webkitallowfullscreen="webkitallowfullscreen" ' .
                  'mozallowfullscreen="mozallowfullscreen" ' .
                  'oallowfullscreen="oallowfullscreen" ' .
                  'msallowfullscreen="msallowfullscreen" ' .
                  'width="' . $width . '" height="' . $height . '">' .
                '</iframe>';

        // Build the oEmbed document
        $response = new stdClass();
        $response->type = 'rich';
        $response->version = 1.0;
        $response->provider_name = 'Datawrapper';
        $response->provider_url = 'http://' . $GLOBALS['dw_config']['domain'];
        $response->title = $chart->getTitle();
        $response->html = $html;
        $response->width = $width;
        $response->height = $height;

        if ($chart->getUser() && $chart->getUser()->getName()) {
            // The author has a name, so report that as well
            $response->author_name = $chart->getUser()->getName();
        }

        if ($chart->hasPreview()) {
            // The chart has a thumbnail, so send that along as well
            $local_path = '../../charts/static/' . $chart->getID() . '/m.png';
            list($thumb_width, $thumb_height) = getimagesize($local_path);
            $response->thumbnail_url = $chart->thumbUrl();
            $response->thumbnail_height = $thumb_height;
            $response->thumbnail_width = $thumb_width;
        }

        // Output the response as a JSON document
        $app->response()->header('Content-Type', 'application/json;charset=utf-8');
        print json_encode($response);
    }

}