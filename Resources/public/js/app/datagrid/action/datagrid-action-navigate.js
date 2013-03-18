/**
 * Navigate action. Changes window location to url, generated by generateUrl method
 *
 * @class   OroApp.DatagridActionNavigate
 * @extends OroApp.DatagridAction
 */
OroApp.DatagridActionNavigate = OroApp.DatagridAction.extend({

    /**
     * If `true` then created launcher will be complete clickable link with generated url,
     * If `false` redirection will be delegated to execute method.
     *
     * @property {Boolean}
     */
    useDirectLauncherLink: true,

    /**
     * Initialize launcher options with url
     *
     * @param {Object} options
     * @param {Boolean} options.useDirectLauncherLink
     */
    initialize: function(options) {
        OroApp.DatagridAction.prototype.initialize.apply(this, arguments);

        if (options.useDirectLauncherLink) {
            this.useDirectLauncherLink = options.useDirectLauncherLink;
        }

        if (this.useDirectLauncherLink) {
            this.launcherOptions = _.extend({
                link: this.generateUrl(),
                runAction: false
            }, this.launcherOptions);
        }
    },

    /**
     * Execute redirect
     */
    execute: function() {
        window.location.href = this.generateUrl();
    }
});
