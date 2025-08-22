const path = require('path');

module.exports = {
  entry: {
    admin: './assets/js/aiohm-booking-mvp-admin.js',
    calendar: './assets/js/aiohm-booking-mvp-calendar.js',
    frontend: './assets/js/aiohm-booking-mvp-app.js',
    help: './assets/js/aiohm-booking-mvp-admin-help.js',
    notifications: './assets/js/aiohm-booking-mvp-notifications.js',
  },
  output: {
    path: path.resolve(__dirname, 'assets/dist/js'),
    filename: '[name].bundle.js',
    clean: true,
  },
  module: {
    rules: [
      {
        test: /\.js$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: ['@babel/preset-env']
          }
        }
      }
    ]
  },
  externals: {
    jquery: 'jQuery'
  },
  devtool: 'source-map',
};