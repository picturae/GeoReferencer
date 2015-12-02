module.exports = function (gulp, deps, taskConfig, fullConfig) {
    var _ = require('underscore');

    gulp.task(
        'proxy-html',
        function () {
            return gulp.src(taskConfig.src)
                .pipe(deps.tokenReplace({tokens: taskConfig.tokens}))
                .pipe(deps.rename(taskConfig.name))
                .pipe(gulp.dest(taskConfig.target));
        }
    );
}
