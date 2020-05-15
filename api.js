const Joi = require('@hapi/joi');
const Boom = require('@hapi/boom');
const get = require('lodash/get');

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

        // register new event type
        event.GET_PUBLISHED_URL_PATTERN = 'GET_PUBLISHED_URL_PATTERN';

        // Register the standard URLs for the URL patterns
        const { chartDomain } = server.methods.config('general');
        events.on(
            event.GET_PUBLISHED_URL_PATTERN,
            () => `http[s]?://${chartDomain}/(?<id>[a-zA-Z0-9]+)(?:/[0-9]+)?(?:/(?:index.html)?)?`
        );

        // Register the API endpoint
        server.route({
            path: '/',
            method: 'GET',
            options: {
                validate: {
                    query: Joi.object({
                        format: Joi.string().valid('json').default('json'),
                        url: Joi.string()
                            .required()
                            .uri({
                                scheme: ['http', 'https'],
                                allowRelative: false
                            }),
                        maxwidth: Joi.number(),
                        maxheight: Joi.number(),
                        iframe: Joi.boolean().allow('')
                    })
                }
            },
            handler: async (request, h) => {
                // Get the parameters from the query-parameters
                const { url, iframe } = request.query;
                let { maxwidth, maxheight } = request.query;

                // Get all the possible patterns for chart urls
                const patterns = await events.emit(
                    event.GET_PUBLISHED_URL_PATTERN,
                    {},
                    { filter: 'success' }
                );

                // Find the first pattern that matches the current url
                let match;
                for (let i = 0; i < patterns.length; i++) {
                    match = new RegExp(patterns[i]).exec(url);
                    if (match) break;
                }

                if (!match) return Boom.notFound();

                // Extract the id. If there is a named capture called 'id', then
                // use that. Otherwise, assume the id is in the first chapture
                // group
                const chartId = match.groups && match.groups.id ? match.groups.id : match[1];

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

                const embedCodes = get(chart, 'metadata.publish.embed-codes');
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
    }
};
