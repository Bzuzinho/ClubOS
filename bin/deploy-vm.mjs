#!/usr/bin/env node

import { existsSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

function requiredEnv(name) {
    const value = process.env[name]?.trim();

    if (!value) {
        console.error(`\n❌ Variável de ambiente obrigatória em falta: ${name}`);
        process.exit(1);
    }

    return value;
}

const VM_USER = requiredEnv('VM_USER');
const VM_HOST = requiredEnv('VM_HOST');
const VM_APP_DIR = requiredEnv('VM_APP_DIR');
const REMOTE_BACKEND_SCRIPT = 'bin/remote-deploy-backend.sh';
const remote = `${VM_USER}@${VM_HOST}`;
const npmCli = process.env.npm_execpath;
const knownHosts = `${requiredEnv('HOME')}/.ssh/known_hosts`;
const sshOptions = [
    '-o', 'BatchMode=yes',
    '-o', 'ConnectTimeout=15',
    '-o', 'StrictHostKeyChecking=yes',
    '-o', `UserKnownHostsFile=${knownHosts}`,
];

if (!VM_APP_DIR.startsWith('/')) {
    console.error('\n❌ VM_APP_DIR tem de ser um caminho absoluto.');
    process.exit(1);
}

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

function shellQuote(value) {
    return `'${String(value).replaceAll("'", `'"'"'`)}'`;
}

console.log('==> ClubOS deploy VM — atomic releases');
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

const repoUrl = output('git', ['remote', 'get-url', 'origin']).replace(/^(https:\/\/)[^/@]+@/, '$1');

console.log('\n==> Build frontend isolado no runner');
runNpm(['ci']);
runNpm(['run', 'build'], {
    env: {
        ...process.env,
        NODE_OPTIONS: process.env.NODE_OPTIONS || '--max-old-space-size=4096',
    },
});

if (!existsSync('public/build/manifest.json')) {
    console.error('\n❌ Build não gerou public/build/manifest.json. Deploy abortado antes de tocar na VM.');
    process.exit(1);
}

if (!existsSync(REMOTE_BACKEND_SCRIPT)) {
    console.error(`\n❌ Script de backend não encontrado: ${REMOTE_BACKEND_SCRIPT}`);
    process.exit(1);
}

const repositoryBundle = join(
    process.env.RUNNER_TEMP || tmpdir(),
    `clubos-repository-${localHead.slice(0, 12)}-${process.pid}.bundle`,
);

console.log('\n==> Empacotar commit Git validado no runner');
run('git', ['bundle', 'create', repositoryBundle, 'HEAD']);
run('git', ['bundle', 'verify', repositoryBundle]);

const remoteTempDir = `/tmp/clubos-atomic-deploy-${localHead.slice(0, 12)}-${process.pid}`;
const remoteBackendScript = `${remoteTempDir}/remote-deploy-backend.sh`;
const remoteBuildDir = `${remoteTempDir}/build`;
const remoteRepositoryBundle = `${remoteTempDir}/repository.bundle`;

console.log('\n==> Preparar payload remoto da release');
run('ssh', [...sshOptions, remote, `rm -rf ${shellQuote(remoteTempDir)} && mkdir -p ${shellQuote(remoteTempDir)} ${shellQuote(remoteBuildDir)}`]);
run('scp', [...sshOptions, REMOTE_BACKEND_SCRIPT, `${remote}:${remoteBackendScript}`]);
run('scp', [...sshOptions, repositoryBundle, `${remote}:${remoteRepositoryBundle}`]);
rmSync(repositoryBundle, { force: true });
run('scp', [...sshOptions, '-r', 'public/build/.', `${remote}:${remoteBuildDir}/`]);

const executeBackend = [
    `chmod 700 ${shellQuote(remoteBackendScript)}`,
    `sudo bash ${shellQuote(remoteBackendScript)} ${shellQuote(VM_APP_DIR)} ${shellQuote(VM_USER)} 'www-data' 'www-data' ${shellQuote(localHead)} ${shellQuote(remoteBuildDir)} ${shellQuote(repoUrl)} ${shellQuote(remoteRepositoryBundle)}`,
].join(' && ');

const executeAndCleanup = [
    executeBackend,
    'status=$?',
    `rm -rf ${shellQuote(remoteTempDir)}`,
    'exit $status',
].join('; ');

console.log('\n==> Construir release, validar, migrar e fazer cutover atómico');
run('ssh', [...sshOptions, remote, executeAndCleanup]);

console.log('\n==> Verificação final através do path produtivo');
run('ssh', [...sshOptions, remote, `/usr/local/bin/clubmanager-healthcheck.sh ${shellQuote(VM_APP_DIR)}`]);

console.log('\n✅ Deploy atómico completo OK.');
console.log(`🌍 Produção actualizada em ${VM_HOST}`);
console.log(`🔖 Commit: ${localHead}`);
