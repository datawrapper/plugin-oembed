# oEmbed plugin for Datawrapper

This plugin adds oEmbed functionality to Datawrapper. The API-endpoint will be `/api/plugin/oembed`.
Any charts published after the installation of this plugin will have a oEmbed-discovery link inserted
in the head of the charts html.

## Supporting oEmbed in publish-plugins.

Any plugins that can publish charts to different urls needs to register a regular expression for
extracting the chart-id of the URLs of published charts.

This can be done using the `DatawrapperPlugin_Oembed::GET_PUBLISHED_URL_PATTERN`-hook.

An example would be

```php
class DatawrapperPlugin_[PluginName] extends DatawrapperPlugin {
    public function init() {
        if (class_exists('DatawrapperPlugin_Oembed')) {
            DatawrapperHooks::register(
                DatawrapperPlugin_Oembed::GET_PUBLISHED_URL_PATTERN,
                function() {
                    return 'a-regexp-pattern-here';
                }
            );
        }
    }
}
