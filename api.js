const Joi = require('joi');
const Boom = require('@hapi/boom');
const get = require('lodash/get');
const flatten = require('lodash/flatten');

module.exports = {
    name: '@datawrapper/plugin-oembed',
    version: '1.0.0',
    options: {
        routes: {
            prefix: '/oembed'
        }
    },
    register: (server, options) => {
        const { events, event } = server.app;
        const { models } = options;
        const { Chart } = models;

        // register new event types
        event.GET_PUBLISHED_URL_PATTERN = 'GET_PUBLISHED_URL_PATTERN';

        const cloudConfig = server.methods.config('plugins')['publish-cloud'];
        const chartDomain = cloudConfig ? cloudConfig.hostname : false;

        const api = server.methods.config('api');
        const apiDomain = api.subdomain ? `${api.subdomain}.${api.domain}` : api.domain;

        // Register the API endpoint
        server.route({
            path: '/',
            method: 'GET',
            options: {
                tags: ['api'],
                auth: false,
                description: 'Get oEmbed information',
                notes:
                    'Retreive embedding information about a Datawrapper visualization. See our [oEmbed tutorial](https://developer.datawrapper.de/docs/embedding-charts-via-oembed) and the official [oEmbed specification](https://oembed.com/) for more information.',
                validate: {
                    query: Joi.object({
                        format: Joi.string()
                            .valid('json')
                            .default('json')
                            .description('The response format. Currenly only JSON is supported.'),
                        url: Joi.string()
                            .required()
                            .uri({
                                scheme: ['http', 'https'],
                                allowRelative: false
                            })
                            .description(
                                'The URL of the Datawrapper visualization you want to retreive embedding information'
                            ),
                        maxwidth: Joi.number()
                            .integer()
                            .description('The maximum width of the embedded visualization'),
                        maxheight: Joi.number()
                            .integer()
                            .description('The maximum height of the embedded visualization'),
                        iframe: Joi.boolean().allow('').description('Force iframe-only embedding')
                    }).unknown()
                },
                response: {
                    sample: 0,
                    schema: Joi.object({
                        type: Joi.string(),
                        version: Joi.string(),
                        provider_name: Joi.string(),
                        provider_url: Joi.string(),
                        title: Joi.string(),
                        html: Joi.string(),
                        width: Joi.number().integer(),
                        height: Joi.number().integer()
                    }).unknown()
                }
            },
            handler: async (request, h) => {
                // Get the parameters from the query-parameters
                const { url, iframe } = request.query;
                let { maxwidth, maxheight } = request.query;

                function testPatterns(patterns) {
                    // we need to flatten in case a single event
                    // listener returned multiple patterns
                    patterns = flatten(patterns);
                    // Find the first pattern that matches the current url
                    for (let i = 0; i < patterns.length; i++) {
                        const match = new RegExp(patterns[i]).exec(url);
                        if (match) return match;
                    }
                    return false;
                }

                let patterns;
                let match = false;

                if (chartDomain) {
                    // Check the standard URL pattern first
                    patterns = [
                        `http[s]?://${chartDomain}/(?<id>[a-zA-Z0-9]+)(?:/[0-9]+)?(?:/(?:index.html)?)?`
                    ];

                    match = testPatterns(patterns);
                }

                if (!match) {
                    // Not a standard URL, let's check for self-hosted charts
                    // Get all the possible patterns for chart urls
                    patterns = await events.emit(
                        event.GET_PUBLISHED_URL_PATTERN,
                        {},
                        { filter: 'success' }
                    );

                    match = testPatterns(patterns);
                }

                if (!match) return Boom.notFound();

                // Extract the id. If there is a named capture called 'id', then
                // use that. Otherwise, assume the id is in the first chapture
                // group
                const chartId = match.groups && match.groups.id ? match.groups.id : match[1];

                if (!chartId) return Boom.notFound();

                // Check that the chart exists and is public
                const chart = await Chart.findOne({
                    where: {
                        id: chartId,
                        last_edit_step: 5,
                        deleted: false
                    }
                });

                if (!chart) return Boom.notFound();

                const publicURL = chart.public_url;

                let width = get(chart, 'metadata.publish.embed-width');
                let height = get(chart, 'metadata.publish.embed-height');

                if (maxwidth || maxheight) {
                    // We have a bounding, so figure out how large we should return the chart
                    const aspect = height / width;
                    if (
                        (maxwidth && !maxheight) ||
                        (maxwidth && maxheight && aspect < maxheight / maxwidth)
                    ) {
                        maxheight = Math.round(maxwidth * aspect);
                    } else {
                        maxwidth = Math.round(maxheight / aspect);
                    }

                    if (maxheight < height) {
                        // Our bounding box is the smallest, so use that size
                        width = maxwidth;
                        height = maxheight;
                    }
                }

                const embedCodes = get(chart, 'metadata.publish.embed-codes', {});
                let html;

                if (embedCodes['embed-method-responsive'] && !(iframe || iframe === '')) {
                    html = embedCodes['embed-method-responsive'];
                } else {
                    // iframe embedding
                    html = `<iframe src="${publicURL}" frameborder="0" id="datawrapper-chart-${chart.id}" scrolling="no" height="${height}" style="width: 0; min-width: 100% !important;" ></iframe>`;
                }

                return {
                    type: 'rich',
                    version: '1.0',
                    provider_name: 'Datawrapper',
                    provider_url: `https://${server.methods.config('api').domain}`,
                    title: chart.title,
                    html,
                    width,
                    height
                };
            }
        });

        if (event.CHART_AFTER_HEAD_HTML) {
            events.on(event.CHART_AFTER_HEAD_HTML, async ({ chart, publish }) => {
                if (publish) {
                    const publicUrl = await events.emit(
                        event.GET_NEXT_PUBLIC_URL,
                        { chart },
                        { filter: 'first' }
                    );
                    return `<link rel="alternate" type="application/json+oembed"
      href="https://${apiDomain}/v3/oembed?url=${publicUrl}&format=json"
      title="oEmbed" />`;
                }
            });
        }
    }
};
