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
        if (empty($url)) {
            return self::error(400, "missing parameter 'url'");
        }
        if (empty($app->request()->get('format'))) {
            return self::error(400, "missing parameter 'format'");
        }

        // Get all the possible patterns for chart urls
        $patterns = DatawrapperHooks::execute(DatawrapperPlugin_Oembed::GET_PUBLISHED_URL_PATTERN);

        // Find the first pattern that matches the current url
        $found = false;
        $id = "";

        foreach ($patterns as $pattern) {
            if (preg_match('|^' . $pattern . '$|', $url, $matches)) {
                // We have a match.

                // Extract the id. If there is a named capture called 'id', then
                // use that. Otherwise, assume the id is in the first chapture
                // group
                $id = isset($matches['id']) ? $matches['id'] : $matches[1];
                // and signal that we found a url
                $found = true;
                break;
            }
        }

        if (!$found) {
            $parsedUrl = parse_url($url, PHP_URL_PATH);
            $id = explode("/", $parsedUrl);

            if (sizeof($id) > 1 && strlen($id[1]) == 5) {
                $id = $id[1];
                $found = true;
            }
        }

        if (!$found) {
            return self::error(400, 'this doesn\'t look like a datawrapper chart url');
        }

        // Check that the chart exists
        $chart = ChartQuery::create()->findPK($id);
        if (!$chart) return self::error(404, 'chart not found');

        // And check that the chart is public
        if (!$chart->isPublic()) return self::error(404, 'chart not found');;

        // make sure that the public url matches the requested url
        $urlHost = parse_url($url, PHP_URL_HOST);
        $chartHost = parse_url($chart->getPublicUrl(), PHP_URL_HOST);
        if ($urlHost != $chartHost) return self::error(404, 'chart not found');

        // Get the oEmbed response
        self::chart_oembed($app, $chart);
    }

    /*
     * Print the oEmbed discovery-link in the chart head html.
     */
    protected function oembedLink($chart) {
        $content = get_chart_content($chart, $chart->getUser(), false, '../');

        $title = htmlspecialchars(strip_tags(str_replace('<br />', ' - ', $chart->getTitle())), ENT_QUOTES, 'UTF-8');
        $url = urlencode($content['chartUrl']);

        echo '<link rel="alternate" type="application/json+oembed" href="' . $content['DW_DOMAIN'] . 'api/plugin/oembed?url=' . $url . '&amp;format=json" title="' . $title . '" />' . "\n";
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
            return self::error(501, 'we only support json format');
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

        if (!empty($chart->getMetadata("publish.embed-codes.embed-method-responsive")) && !$app->request()->get('iframe')) {
            $html = $chart->getMetadata("publish.embed-codes.embed-method-responsive");
        } else {
            $html = '<iframe src="' . $url . '" frameborder="0" ' .
                      'id="datawrapper-chart-' . $chart->getId() . '" ' .
                      'scrolling="no" style="width: 0; min-width: 100% !important;" ' .
                      'height="' . $height . '">' .
                    '</iframe>';
        }


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

        // Output the response as a JSON document
        $app->response()->header('Content-Type', 'application/json;charset=utf-8');
        print json_encode($response);
    }

    protected static function error($code, $message) {
        global $app;
        $app->response()->status($code);
        $app->response()->header('Content-Type', 'application/json;charset=utf-8');
        print json_encode(['error' => $message]);
    }

}
