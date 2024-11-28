const cluster = require('cluster');
const { createClient } = require('bedrock-protocol');
const { v4: uuidv4 } = require('uuid'); // Utilisé pour générer un UUID

if (cluster.isMaster) {
    const totalBots = 200;
    const botsPerCluster = 20;

    const numClusters = Math.ceil(totalBots / botsPerCluster);

    console.log(`Lancement de ${numClusters} clusters pour gérer ${totalBots} bots.`);

    cluster.on('exit', (worker, code, signal) => {
        console.log(`Cluster ${worker.process.pid} s'est arrêté.`);
    });

    process.on('SIGINT', () => {
        console.log('Signal SIGINT reçu. Déconnexion de tous les bots...');
        for (const id in cluster.workers) {
            cluster.workers[id].send('disconnect');
        }
    });

    process.on('SIGTERM', () => {
        console.log('Signal SIGTERM reçu. Déconnexion de tous les bots...');
        for (const id in cluster.workers) {
            cluster.workers[id].send('disconnect');
        }
    });
    // Fonction pour créer des bots par groupes de 10 avec un délai
    async function test(start, end, host, port) {
        // Création des clusters
        for (let i = 0; i < numClusters; i++) {
            const start = i * botsPerCluster + 1;
            const end = Math.min(start + botsPerCluster - 1, totalBots);
            cluster.fork({ START_INDEX: start, END_INDEX: end });
            await new Promise(resolve => setTimeout(resolve, 1000)); // Attendre 500 ms entre les groupes
        }
    }
    test();
} else {
    const startIndex = parseInt(process.env.START_INDEX, 10);
    const endIndex = parseInt(process.env.END_INDEX, 10);

    const serverHost = '178.32.110.45';
    const serverPort = 19132;

    console.log(`Cluster ${process.pid} gère les bots de ${startIndex} à ${endIndex}.`);

    const clients = [];
    function createBot(username, host, port, maxRetries = 10) {
        let retries = 0;

        function connect() {
            const client = createClient({
                host: host || 'localhost',
                port: port || 19132,
                username: username,
                offline: true
            });

            clients.push(client);

            client.on('join', () => {
                console.log(`${username} a rejoint le serveur.`);
                retries = 0;

            });

            client.on('spawn', (ff) => {
                console.log(ff)
                setInterval(() => {
                    client.queue('text', {
                        type: 'chat', needs_translation: false, source_name: client.username, xuid: '', platform_chat_id: '', filtered_message: '',
                        message: `${new Date().toLocaleString()}`
                    });
                }, 1000);
            })
            client.on('disconnect', (reason) => {
                console.log(`${username} a été déconnecté: ${reason}`);
                if (retries < maxRetries) {
                    retries++;
                    console.log(`${username} tente de se reconnecter (${retries}/${maxRetries})...`);
                    setTimeout(connect, 3000);
                } else {
                    console.log(`${username} a atteint le maximum de tentatives de reconnexion.`);
                }
            });

            client.on('error', (err) => {
                if (err.message.includes('RakNet') && retries < maxRetries) {
                    retries++;
                    console.log(`${username} tente de se reconnecter suite à une erreur RakNet (${retries}/${maxRetries})...`);
                    setTimeout(connect, 3000);
                } else if (retries >= maxRetries) {
                    console.log(`${username} a atteint le maximum de tentatives de reconnexion après une erreur.`);
                }
            });

            return client;
        }

        connect();
    }
    async function createBotsInBatches(start, end, host, port) {
        const batchSize = 10;
        for (let i = start; i <= end; i += batchSize) {
            const batchEnd = Math.min(i + batchSize - 1, end);
            console.log(`Cluster ${process.pid} ajoute les bots de ${i} à ${batchEnd}.`);
            for (let j = i; j <= batchEnd; j++) {
                const botName = `Bot_${Math.random().toString(36).substring(7)}`;
                createBot(botName, host, port);
            }
            if (batchEnd < end) {
                await new Promise(resolve => setTimeout(resolve, 500)); // Attendre 500 ms entre les groupes
            }
        }

        // Lancer l'exécution périodique des commandes toutes les 20 secondes
    }

    // Lancer les bots en groupes
    createBotsInBatches(startIndex, endIndex, serverHost, serverPort);

    process.on('message', (msg) => {
        if (msg === 'disconnect') {
            console.log(`Cluster ${process.pid} déconnecte tous ses clients...`);
            clients.forEach(client => client.disconnect('Déconnexion forcée par le maître.'));
            process.exit(0);
        }
    });
}
