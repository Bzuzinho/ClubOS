#!/usr/bin/env node

import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const VM_USER = process.env.VM_USER || 'ubuntu';
const VM_HOST = process.env.VM_HOST || '129.159.13.211';
const VM_APP_DIR = process.env.VM_APP_DIR || '/var/www/clubmanager';
const remote = `${VM_USER}@${VM_HOST}`;
const npmCli = process.env.npm_execpath;

function run(command, args, options = {}) {
    console.log(`\n> ${command} ${args.join(' ')}`);

    const result = spawnSync(command, args, {
        stdio: 'inherit',
        shell: false,
        ...options,
    });

    if (result.error) {
        console.error(`\n❌ Não foi possível executar ${command}: ${result.error.message}`);
        process.exit(1);
    }

    if (result.status !== 0) {
        console.error(`\n❌ Comando falhou com código ${result.status}: ${command}`);
        process.exit(result.status || 1);
    }
}

function output(command, args) {
    const result = spawnSync(command, args, {
        encoding: 'utf8',
        shell: false,
    });

    if (result.error || result.status !== 0) {
        console.error(`\n❌ Falha ao executar ${command} ${args.join(' ')}`);
        if (result.stderr) {
            console.error(result.stderr.trim());
        }
        process.exit(result.status || 1);
    }

    return result.stdout.trim();
}

function runNpm(args, options = {}) {
    if (!npmCli) {
        console.error('\n❌ npm_execpath não está disponível. Executa o deploy através de npm run deploy:vm.');
        process.exit(1);
    }

    run(process.execPath, [npmCli, ...args], options);
}

console.log('==> ClubOS deploy VM');
console.log(`    Repo: ${process.cwd()}`);
console.log(`    VM:   ${remote}:${VM_APP_DIR}`);

const branch = output('git', ['branch', '--show-current']);
if (branch !== 'main') {
    console.error(`\n❌ Tens de estar na branch main. Branch actual: ${branch || '(desconhecida)'}`);
    process.exit(1);
}

const status = output('git', ['status', '--porcelain']);
if (status !== '') {
    console.error('\n❌ Existem alterações locais não commitadas. Faz commit/push antes do deploy.');
    process.exit(1);
}

run('git', ['fetch', 'origin', 'main']);

const localHead = output('git', ['rev-parse', 'HEAD']);
const remoteHead = output('git', ['rev-parse', 'origin/main']);
if (localHead !== remoteHead) {
    console.error('\n❌ A main local não corresponde a origin/main. Executa git pull origin main antes do deploy.');
    process.exit(1);
}

console.log('\n==> Build frontend');
runNpm(['ci']);
runNpm(['run', 'build'], {
    env: {
        ...process.env,
        NODE_OPTIONS: process.env.NODE_OPTIONS || '--max-old-space-size=4096',
    },
});

if (!existsSync('public/build/manifest.json')) {
    console.error('\n❌ Build não gerou public/build/manifest.json. Deploy abortado.');
    process.exit(1);
}

console.log('\n==> Normalizar permissões do repositório na VM');
const prepareRemoteRepository = [
    `test -d '${VM_APP_DIR}/.git'`,
    `GIT_DIR="$(sudo git -C '${VM_APP_DIR}' rev-parse --absolute-git-dir)"`,
    `WORK_TREE="$(sudo git -C '${VM_APP_DIR}' rev-parse --show-toplevel)"`,
    `test -n "$GIT_DIR"`,
    `test -d "$GIT_DIR"`,
    `test -n "$WORK_TREE"`,
    `sudo find "$WORK_TREE" -xdev ! -path "$WORK_TREE/.env" -exec chown www-data:www-data {} +`,
    `sudo find "$WORK_TREE" -xdev ! -path "$WORK_TREE/.env" -exec chmod u+rwX {} +`,
    `sudo chown -R www-data:www-data "$GIT_DIR"`,
    `sudo chmod -R u+rwX "$GIT_DIR"`,
    `sudo rm -f "$GIT_DIR/index.lock" "$GIT_DIR/FETCH_HEAD.lock"`,
    `sudo rm -f "$GIT_DIR/FETCH_HEAD"`,
    `sudo -u www-data -H sh -c "umask 0022; : > '$GIT_DIR/FETCH_HEAD'; test -w '$GIT_DIR/FETCH_HEAD'"`,
    `sudo -u www-data -H git -C "$WORK_TREE" rev-parse --is-inside-work-tree | grep -qx true`,
].join(' && ');
run('ssh', [remote, prepareRemoteRepository]);

console.log('\n==> Deploy backend');
run('ssh', [
    remote,
    `/usr/local/bin/clubmanager-deploy-backend.sh '${VM_APP_DIR}'`,
]);

const tempBuildDir = `/tmp/clubos-build-${process.pid}`;

console.log('\n==> Upload frontend');
run('scp', ['-r', 'public/build', `${remote}:${tempBuildDir}`]);

const publishFrontend = [
    `sudo rm -rf '${VM_APP_DIR}/public/build'`,
    `sudo mkdir -p '${VM_APP_DIR}/public/build'`,
    `sudo cp -R '${tempBuildDir}/.' '${VM_APP_DIR}/public/build/'`,
    `sudo chown -R www-data:www-data '${VM_APP_DIR}/public/build'`,
    `rm -rf '${tempBuildDir}'`,
    '/usr/local/bin/clubmanager-frontend-reload.sh',
    `/usr/local/bin/clubmanager-healthcheck.sh '${VM_APP_DIR}'`,
].join(' && ');

run('ssh', [remote, publishFrontend]);

console.log('\n✅ Deploy completo OK.');
console.log(`🌍 Produção actualizada em ${VM_HOST}`);
