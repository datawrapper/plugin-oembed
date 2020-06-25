# oEmbed plugin for Datawrapper

This plugin adds oEmbed functionality to Datawrapper. 

Here you can find more information about how to use this:
* https://developer.datawrapper.de/docs/embedding-charts-via-oembed 
* https://developer.datawrapper.de/reference#oembed

## Supporting oEmbed in publish-plugins.

Any plugins that can publish charts to different urls needs to register a regular expression for
extracting the chart-id of the URLs of published charts.

This can be done using the `GET_PUBLISHED_URL_PATTERN` event.

An example would be

```js
// in api.js

module.exports = {
    pkg: require('./package.json'),
    register: (server) => {
        const { events, event } = server.app;
        // wait until all plugins are loaded
        events.on(event.PLUGINS_LOADED, () => {
            // check if oembed url pattern event is registered
            if (event.GET_PUBLISHED_URL_PATTERN) {
                // register custom url patterns
                events.on(event.GET_PUBLISHED_URL_PATTERN, async () => {
                    return ['a-regexp-pattern-here']
                });
            }
        });
    }
}
```

