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
                return 'http[s]?:\/\/' . $GLOBALS['dw_config']['chart_domain'] . '\/(?<id>.+?)([\/](index\.html)?)?';
            }
        );
    }

    /*
     * Register chart_oembed.php as a required library so it gets autoloaded
     */
    public function getRequiredLibraries() {
        return array('chart_oembed.php');
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
                chart_oembed($app, $chart);

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
}