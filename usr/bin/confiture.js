var that = this;

var args = that.getArguments(), term = that.getTerminal();

Webos.require([
	'/usr/lib/apt/apt.js',
	'/usr/lib/confiture/webos.js'
], function() {
	var options = args.getOptions(), targets = args.getParams();

	var operations = {
		refresh: function() {
			term.echo(':: Refreshing packages databases...\n');

			var op = new Webos.Operation();

			Webos.Confiture.Package.updateCache([function(result) {
				for(var i = 0; i < result.updated.length; i++) {
					term.echo(' '+result.updated[i]+' updated\n');
				}
				op.setCompleted();
			}, function(res) {
				term.echo('error: '+res.getError().message);
				op.setCompleted(false);
			}]);

			return op;
		},
		sysupgrade: function() {
			term.echo(':: Starting full system upgrade...\n');

			var op = new Webos.Operation();

			//targets.concat(data)
			//TODO
			setTimeout(function() {
				op.setCompleted();
			}, 0);

			return op;
		},
		install: function() {
			var op = new Webos.Operation();

			if (targets.length == 0) {
				setTimeout(function() {
					op.setCompleted();
				}, 0);
				return;
			}

			term.echo('Reading packages list...\n');

			var pkgs = [];
			var gotPkg = function (pkg) {
				pkgs.push(pkg);

				if (pkgs.length == targets.length) {
					doInstall();
				}
			};

			var doInstall = function () {
				term.echo('\nPackages ('+pkgs.length+'):');

				var totalSize = 0, totalExtractedSize = 0;
				for (var i = 0; i < pkgs.length; i++) {
					var pkg = pkgs[i];

					term.echo(' '+pkg.get('name')+'-'+pkg.get('version'));

					totalSize += pkg.get('size');
					totalExtractedSize += pkg.get('extractedSize');
				}

				term.echo('\n\nTotal download size: '+Webos.File.bytesToSize(totalSize)+'\n');
				term.echo('Total installed size: '+Webos.File.bytesToSize(totalExtractedSize)+'\n');

				term.prompt(function(val) {
					if (val == 'Y') {
						term.echo('\nTODO !');
						that.stop();
					} else {
						that.stop();
					}
				}, {
					label: ':: Proceed with installation?',
					type: 'yn'
				});
			};

			var pkgNotFound = function (resp) {
				term.echo(resp);
				that.stop();
			};

			//TODO: install packages
			for(var i = 0; i < targets.length; i++) {
				Webos.Confiture.Package.get(targets[i], [function (pkg) {
					gotPkg(pkg);
				}, function (resp) {
					pkgNotFound(resp);
				}]);
			}

			return op;
		}
	};

	if (args.isOption('S') || args.isOption('sync')) { // Synchronize
		var addOptToTargets = function (optionName) {
			if (args.getOption(optionName)) {
				targets.push(args.getOption(optionName));
			}
		};
		addOptToTargets('S');
		addOptToTargets('sync');

		var operationsToExecute = [];

		if (args.isOption('y') || args.isOption('refresh')) { // Refresh cache
			operationsToExecute.push('refresh');

			addOptToTargets('y');
			addOptToTargets('refresh');
		}

		if (args.isOption('u') || args.isOption('sysupgrade')) { // Upgrade system
			operationsToExecute.push('sysupgrade');

			addOptToTargets('u');
			addOptToTargets('sysupgrade');
		}

		if (args.isOption('h') || args.isOption('help')) { // Help
			term.echo('usage: confiture {-S --sync} [options] [package(s)]\n');
			term.echo('options:\n');
			term.echo('  -u, --sysupgrade         upgrade all installed packages\n');
			term.echo('  -y, --refresh            update databases from server\n');
			that.stop();
			return;
		}

		if (operationsToExecute.length == 0 && !targets.length) {
			term.echo('error: no target specified (use -h to display help)\n');
			that.stop();
			return;
		}

		var opId = 0;
		var executeOperation = function() {
			if (operationsToExecute[opId]) {
				var opName = operationsToExecute[opId];
			} else if (targets.length) {
				var opName = 'install';
			} else {
				that.stop();
				return;
			}

			var op = operations[opName]();

			op.on('success', function() {
				opId++;

				if (opName == 'install') {
					targets = [];
				}

				executeOperation();
			});
			op.on('error', function() {
				that.stop();
			});
		};

		executeOperation();
	} else if (args.isOption('h') || args.isOption('help')) { // Help
		term.echo('usage: confiture <operation> [...]\n');
		term.echo('confiture {-h --help}\n');
		term.echo('confiture {-V --version}\n');
		term.echo('confiture {-S --sync}     [options] [package(s)]');
		that.stop();
	} else if (args.isOption('V') || args.isOption('version')) { // Version
		term.echo('Confiture v1.0 alpha 1');
		that.stop();
	} else {
		term.echo('error: no operation specified (use -h to display help)');
		that.stop();
	}
});